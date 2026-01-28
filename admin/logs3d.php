<?php
require_once __DIR__ . "/../includes/auth_admin.php";
if (session_status() === PHP_SESSION_NONE) session_start();

if (!defined("BASE_URL")) define("BASE_URL", "/uiu_brainnext");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>3D Audit Trail - UIU BrainNext</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    html, body { margin:0; height:100%; overflow:hidden; font-family: system-ui, Arial; }
    #hud{
      position:fixed; top:12px; left:12px; z-index:10;
      background:rgba(0,0,0,.65); color:#fff;
      padding:12px 14px; border-radius:12px; font-size:14px;
      max-width:520px;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }
    #row { display:flex; gap:10px; align-items:center; margin-top:10px; flex-wrap:wrap; }
    select, input{
      padding:8px 10px;
      border-radius:10px;
      border:1px solid rgba(255,255,255,.25);
      background:rgba(255,255,255,.08);
      color:#fff;
      outline:none;
    }
    option { color:#000; }
    a.btn{
      display:inline-block; color:#fff; text-decoration:none;
      background:rgba(255,255,255,.12);
      padding:8px 12px; border-radius:10px;
      border:1px solid rgba(255,255,255,.18);
    }
    #tip{
      position:fixed; pointer-events:none; z-index:20;
      background:rgba(20,20,20,.92); color:#fff;
      padding:10px 12px; border-radius:10px; font-size:13px;
      transform: translate(12px, 12px);
      display:none; white-space:pre; max-width:560px;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }
    .muted{ opacity:.85; font-size:12px; line-height:1.35; margin-top:8px; }
  </style>
</head>
<body>
  <div id="hud">
    <div style="font-weight:900;">3D Audit Trail (Logs Timeline)</div>
    <div class="muted">
      Hover dots for details. Click a dot to open logs page. Use filters to focus suspicious actions.
    </div>

    <div id="row">
      <select id="actionFilter">
        <option value="">All actions</option>
      </select>

      <input id="actorFilter" placeholder="Filter actor (name/id)" style="width:180px;" />

      <select id="limitSel">
        <option value="150">150 logs</option>
        <option value="250" selected>250 logs</option>
        <option value="400">400 logs</option>
        <option value="800">800 logs</option>
      </select>

      <a class="btn" href="<?php echo BASE_URL; ?>/admin/index.php">Back</a>
      <a class="btn" href="<?php echo BASE_URL; ?>/admin/meta_logs.php">Open Logs Table</a>
    </div>

    <div class="muted" id="status">Loading…</div>
  </div>

  <div id="tip"></div>

  <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>

  <script>
    const BASE_URL = "<?php echo BASE_URL; ?>";

    const statusEl = document.getElementById("status");
    const tip = document.getElementById("tip");
    const actionFilter = document.getElementById("actionFilter");
    const actorFilter  = document.getElementById("actorFilter");
    const limitSel     = document.getElementById("limitSel");

    // --- Three.js setup ---
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x070b18);
    scene.fog = new THREE.Fog(0x070b18, 120, 620);

    const camera = new THREE.PerspectiveCamera(55, window.innerWidth/window.innerHeight, 0.1, 2000);
    camera.position.set(0, 130, 260);

    const renderer = new THREE.WebGLRenderer({ antialias:true });
    renderer.setSize(window.innerWidth, window.innerHeight);
    renderer.setPixelRatio(window.devicePixelRatio || 1);
    document.body.appendChild(renderer.domElement);

    const controls = new THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.minDistance = 80;
    controls.maxDistance = 900;

    scene.add(new THREE.AmbientLight(0xffffff, 0.60));
    const dir = new THREE.DirectionalLight(0xffffff, 0.95);
    dir.position.set(120, 160, 60);
    scene.add(dir);

    const grid = new THREE.GridHelper(1200, 80, 0x233055, 0x141c33);
    grid.position.y = -30;
    grid.material.opacity = 0.45;
    grid.material.transparent = true;
    scene.add(grid);

    // Timeline axis
    const axisMat = new THREE.LineBasicMaterial({ color: 0x86a2ff, transparent:true, opacity:0.8 });
    const axisGeom = new THREE.BufferGeometry().setFromPoints([
      new THREE.Vector3(-450, -10, 0),
      new THREE.Vector3( 450, -10, 0),
    ]);
    const axis = new THREE.Line(axisGeom, axisMat);
    scene.add(axis);

    // Data + meshes
    let rawLogs = [];
    let dotMeshes = [];
    const raycaster = new THREE.Raycaster();
    const mouse = new THREE.Vector2();
    let hovered = null;

    function catColor(action) {
      const a = (action || "").toLowerCase();

      if (a.includes("delete") || a.includes("remove")) return 0xef4444; // red
      if (a.includes("update") || a.includes("edit"))   return 0xf59e0b; // orange
      if (a.includes("create") || a.includes("add"))    return 0x22c55e; // green
      if (a.includes("login") || a.includes("auth"))    return 0x60a5fa; // blue
      if (a.includes("enroll"))                         return 0xa855f7; // purple
      return 0x9ca3af; // gray
    }

    function laneZ(action) {
      const a = (action || "").toLowerCase();
      // lanes reduce overlap (like categories)
      if (a.includes("delete") || a.includes("remove")) return -80;
      if (a.includes("update") || a.includes("edit"))   return -40;
      if (a.includes("create") || a.includes("add"))    return 0;
      if (a.includes("login") || a.includes("auth"))    return 40;
      return 80;
    }

    function parseTime(t) {
      // Accepts "YYYY-MM-DD HH:MM:SS" or anything Date can parse
      const d = new Date(t);
      if (!isNaN(d.getTime())) return d.getTime();
      // fallback: treat as now
      return Date.now();
    }

    function clearDots() {
      dotMeshes.forEach(m => scene.remove(m));
      dotMeshes = [];
      hovered = null;
      tip.style.display = "none";
    }

    function buildDots(logs) {
      clearDots();
      if (!logs.length) {
        statusEl.textContent = "No logs matched your filters.";
        return;
      }

      // Map time → x range [-420..420]
      const times = logs.map(l => parseTime(l.t));
      const minT = Math.min(...times);
      const maxT = Math.max(...times);
      const span = Math.max(1, maxT - minT);

      const dotGeo = new THREE.SphereGeometry(4.2, 18, 18);

      logs.forEach((l, idx) => {
        const tt = parseTime(l.t);
        const x = -420 + ((tt - minT) / span) * 840;
        const z = laneZ(l.action);

        // small “severity” effect (delete is bigger)
        const isDanger = (l.action || "").toLowerCase().includes("delete") || (l.action || "").toLowerCase().includes("remove");
        const scale = isDanger ? 1.35 : 1.0;

        const mat = new THREE.MeshStandardMaterial({
          color: catColor(l.action),
          metalness: 0.25,
          roughness: 0.35,
          emissive: new THREE.Color(0x0b1638),
          emissiveIntensity: 0.9
        });

        const dot = new THREE.Mesh(dotGeo, mat);
        dot.position.set(x, -5 + (idx % 8) * 1.4, z); // slight y jitter to avoid perfect overlap
        dot.scale.set(scale, scale, scale);
        dot.userData = l;
        scene.add(dot);
        dotMeshes.push(dot);
      });

      statusEl.textContent = `Showing ${logs.length} logs (hover dots). Lanes: Create/Add, Update/Edit, Login/Auth, Delete/Remove, Other.`;
    }

    function fillActionDropdown(logs) {
      const set = new Set();
      logs.forEach(l => {
        const a = (l.action || "").trim();
        if (a) set.add(a);
      });
      const list = Array.from(set).sort((a,b)=>a.localeCompare(b));
      // reset (keep first All option)
      actionFilter.innerHTML = '<option value="">All actions</option>';
      list.slice(0, 120).forEach(a => {
        const opt = document.createElement("option");
        opt.value = a;
        opt.textContent = a;
        actionFilter.appendChild(opt);
      });
    }

    function applyFilters() {
      const a = (actionFilter.value || "").trim().toLowerCase();
      const actorQ = (actorFilter.value || "").trim().toLowerCase();

      const filtered = rawLogs.filter(l => {
        const okA = !a || (l.action || "").toLowerCase() === a;
        const okActor = !actorQ || (l.actor || "").toLowerCase().includes(actorQ);
        return okA && okActor;
      });

      buildDots(filtered);
    }

    async function loadLogs() {
      const limit = parseInt(limitSel.value || "250", 10);
      statusEl.textContent = "Loading logs…";

      const data = await fetch("logs3d_api.php?limit=" + encodeURIComponent(limit)).then(r => r.json());
      if (data.error) {
        statusEl.textContent = "Error: " + data.error;
        return;
      }

      rawLogs = (data.logs || []);
      fillActionDropdown(rawLogs);
      applyFilters();
    }

    // Hover picking
    window.addEventListener("mousemove", (e) => {
      mouse.x = (e.clientX / window.innerWidth) * 2 - 1;
      mouse.y = -(e.clientY / window.innerHeight) * 2 + 1;

      tip.style.left = e.clientX + "px";
      tip.style.top = e.clientY + "px";

      raycaster.setFromCamera(mouse, camera);
      const hits = raycaster.intersectObjects(dotMeshes, false);

      if (hits.length === 0) {
        if (hovered) hovered.material.emissiveIntensity = 0.9;
        hovered = null;
        tip.style.display = "none";
        return;
      }

      const m = hits[0].object;
      if (hovered && hovered !== m) hovered.material.emissiveIntensity = 0.9;

      hovered = m;
      hovered.material.emissiveIntensity = 2.0;

      const d = hovered.userData;
      tip.textContent =
        `Action: ${d.action || "-"}\n` +
        `Time: ${d.t || "-"}\n` +
        `Actor: ${d.actor || "-"}\n` +
        `Entity: ${(d.entity || "-")}${d.entity_id ? " #" + d.entity_id : ""}\n` +
        `IP: ${d.ip || "-"}\n\n` +
        `${(d.details || "").slice(0, 350) || "(no details)" }\n\n` +
        `Click to open logs list`;
      tip.style.display = "block";
    });

    // Click -> open meta_logs page (fallback)
    window.addEventListener("click", () => {
      if (!hovered) return;
      const d = hovered.userData;
      // If your meta_logs.php supports query by id, you can do:
      // window.location.href = BASE_URL + "/admin/meta_logs.php?id=" + encodeURIComponent(d.id);
      window.location.href = BASE_URL + "/admin/meta_logs.php";
    });

    // Filters
    actionFilter.addEventListener("change", applyFilters);
    actorFilter.addEventListener("input", () => {
      // tiny debounce
      clearTimeout(window.__actorT);
      window.__actorT = setTimeout(applyFilters, 120);
    });
    limitSel.addEventListener("change", loadLogs);

    // Render loop
    function animate() {
      requestAnimationFrame(animate);
      controls.update();
      renderer.render(scene, camera);
    }
    animate();

    window.addEventListener("resize", () => {
      camera.aspect = window.innerWidth/window.innerHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(window.innerWidth, window.innerHeight);
    });

    loadLogs();
  </script>
</body>
</html>
