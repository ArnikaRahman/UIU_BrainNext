<?php
require_once __DIR__ . "/../includes/auth_admin.php";
if (session_status() === PHP_SESSION_NONE) session_start();

if (!defined("BASE_URL")) define("BASE_URL", "/uiu_brainnext");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>3D Admin Navigation - UIU BrainNext</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    html, body { margin:0; height:100%; overflow:hidden; font-family: system-ui, Arial; }
    #hud{
      position:fixed; top:12px; left:12px; z-index:10;
      background:rgba(0,0,0,.65); color:#fff;
      padding:12px 14px; border-radius:12px; font-size:14px;
      max-width:460px;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }
    #row { display:flex; gap:10px; align-items:center; margin-top:10px; flex-wrap:wrap; }
    #search {
      width: 220px;
      padding:8px 10px;
      border-radius:10px;
      border:1px solid rgba(255,255,255,.25);
      background:rgba(255,255,255,.08);
      color:#fff;
      outline:none;
    }
    #tip{
      position:fixed; pointer-events:none; z-index:20;
      background:rgba(20,20,20,.92); color:#fff;
      padding:10px 12px; border-radius:10px; font-size:13px;
      transform: translate(12px, 12px);
      display:none; white-space:pre; max-width:520px;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }
    a.btn{
      display:inline-block; color:#fff; text-decoration:none;
      background:rgba(255,255,255,.12);
      padding:8px 12px; border-radius:10px;
      border:1px solid rgba(255,255,255,.18);
    }
    .muted{ opacity:.85; font-size:12px; line-height:1.35; margin-top:8px; }
  </style>
</head>
<body>
  <div id="hud">
    <div style="font-weight:900;">3D Admin Navigation</div>
    <div class="muted">
      Hover a tile → see info. Click → open page. Search → jump camera to tile.
    </div>

    <div id="row">
      <input id="search" placeholder="Search module (users, sections…)" />
      <a class="btn" href="<?php echo BASE_URL; ?>/admin/index.php">Back</a>
    </div>

    <div class="muted" id="status">Loading stats…</div>
  </div>

  <div id="tip"></div>

  <!-- ✅ Non-module Three.js (stable for XAMPP) -->
  <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>

  <script>
    const BASE_URL = "<?php echo BASE_URL; ?>";

    // ---------------------------------------
    // Modules (3D Tiles) you want in Admin UI
    // ---------------------------------------
    // You can add/remove tiles easily here.
    const tiles = [
      { key:"sections",    label:"Manage Sections",  url: BASE_URL + "/admin/sections_manage.php",   group:"Academic" },
      { key:"enrollments", label:"Enroll Students",  url: BASE_URL + "/admin/enrollments_manage.php",group:"Academic" },
      { key:"teachers",    label:"Manage Teachers",  url: BASE_URL + "/admin/teacher_manage.php",    group:"Users" },
      { key:"logs",        label:"Logs",             url: BASE_URL + "/admin/meta_logs.php",         group:"System" },

      // Add more if you have pages:
      // { key:"problems",     label:"Problems",        url: BASE_URL + "/teacher/problems.php", group:"Judge" },
      // { key:"submissions",  label:"Submissions",     url: BASE_URL + "/teacher/submissions.php", group:"Judge" },

      { key:"schema3d_main", label:"3D Schema (Main)", url: BASE_URL + "/admin/schema_3d_demo.php?db=uiu_brainnext",      group:"3D" },
      { key:"schema3d_meta", label:"3D Schema (Meta)", url: BASE_URL + "/admin/schema_3d_demo.php?db=uiu_brainnext_meta", group:"3D" },

      { key:"nav3d", label:"3D Navigation", url: BASE_URL + "/admin/nav3d.php", group:"3D" },
    ];

    // ---------------------------------------
    // Optional: load counts from PHP API
    // ---------------------------------------
    const statusEl = document.getElementById("status");
    async function loadStats() {
      try {
        const data = await fetch("nav3d_stats.php").then(r => r.json());
        tiles.forEach(t => {
          if (data && typeof data[t.key] !== "undefined") t.count = data[t.key];
        });
        statusEl.textContent = "Tip: type a module name to focus it (ex: teachers, logs).";
      } catch (e) {
        statusEl.textContent = "Stats not available (nav3d_stats.php missing). Navigation still works.";
      }
    }

    // ---------------------------------------
    // Three.js scene
    // ---------------------------------------
    const tip = document.getElementById("tip");
    const search = document.getElementById("search");

    let scene = new THREE.Scene();
    scene.background = new THREE.Color(0x070b18);
    scene.fog = new THREE.Fog(0x070b18, 120, 520);

    const camera = new THREE.PerspectiveCamera(55, window.innerWidth / window.innerHeight, 0.1, 2000);
    camera.position.set(0, 120, 220);
    camera.lookAt(0, 0, 0);

    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(window.innerWidth, window.innerHeight);
    renderer.setPixelRatio(window.devicePixelRatio || 1);
    document.body.appendChild(renderer.domElement);

    const controls = new THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.rotateSpeed = 0.6;
    controls.zoomSpeed = 0.9;
    controls.panSpeed = 0.6;
    controls.minDistance = 80;
    controls.maxDistance = 600;

    // Lights
    scene.add(new THREE.AmbientLight(0xffffff, 0.60));
    const dir = new THREE.DirectionalLight(0xffffff, 0.95);
    dir.position.set(80, 120, 60);
    scene.add(dir);

    const rim = new THREE.DirectionalLight(0x9fb5ff, 0.35);
    rim.position.set(-120, 60, -90);
    scene.add(rim);

    // Grid floor
    const grid = new THREE.GridHelper(900, 70, 0x233055, 0x141c33);
    grid.position.y = -22;
    grid.material.opacity = 0.45;
    grid.material.transparent = true;
    scene.add(grid);

    // ---------------------------------------
    // Build tiles in a nice grid (3 columns)
    // ---------------------------------------
    const objects = [];
    const tileByKey = new Map();

    const boxGeo = new THREE.BoxGeometry(46, 18, 28);

    function colorForGroup(group) {
      // simple palette without being too colorful
      switch (group) {
        case "Academic": return 0x2f6fed; // blue
        case "Users":    return 0x10b981; // green
        case "Judge":    return 0xa855f7; // purple
        case "System":   return 0xf59e0b; // orange
        case "3D":       return 0x60a5fa; // sky
        default:         return 0x2f6fed;
      }
    }

    const cols = 3;
    const spacingX = 72;
    const spacingZ = 56;

    tiles.forEach((t, i) => {
      const col = i % cols;
      const row = Math.floor(i / cols);

      const x = (col - 1) * spacingX;
      const z = (row * spacingZ) - 60;

      const mat = new THREE.MeshStandardMaterial({
        color: colorForGroup(t.group),
        metalness: 0.22,
        roughness: 0.35,
        emissive: new THREE.Color(0x0b1638),
        emissiveIntensity: 1.0
      });

      const mesh = new THREE.Mesh(boxGeo, mat);
      mesh.position.set(x, 0, z);
      mesh.userData = t;
      scene.add(mesh);

      const label = makeLabelSprite(t.label, t.group);
      label.position.set(x, 18, z);
      scene.add(label);

      const small = makeSmallSprite(`${t.group}`, 0.85);
      small.position.set(x, -14, z);
      scene.add(small);

      objects.push(mesh);
      tileByKey.set(t.key.toLowerCase(), mesh);
    });

    // ---------------------------------------
    // Hover + tooltip + click redirect
    // ---------------------------------------
    const raycaster = new THREE.Raycaster();
    const mouse = new THREE.Vector2();
    let hovered = null;

    window.addEventListener("mousemove", (e) => {
      mouse.x = (e.clientX / window.innerWidth) * 2 - 1;
      mouse.y = -(e.clientY / window.innerHeight) * 2 + 1;

      tip.style.left = e.clientX + "px";
      tip.style.top = e.clientY + "px";

      raycaster.setFromCamera(mouse, camera);
      const hits = raycaster.intersectObjects(objects, false);

      if (hits.length === 0) {
        if (hovered) hovered.material.emissiveIntensity = 1.0;
        hovered = null;
        tip.style.display = "none";
        return;
      }

      const m = hits[0].object;
      if (hovered && hovered !== m) hovered.material.emissiveIntensity = 1.0;
      hovered = m;
      hovered.material.emissiveIntensity = 2.2;

      const d = hovered.userData;
      const countLine = (typeof d.count !== "undefined") ? `\nCount: ${d.count}` : "";
      tip.textContent = `${d.label}\nGroup: ${d.group}${countLine}\n\nClick to open:\n${d.url}`;
      tip.style.display = "block";
    });

    window.addEventListener("click", () => {
      if (!hovered) return;
      window.location.href = hovered.userData.url;
    });

    // ---------------------------------------
    // Search -> focus tile
    // ---------------------------------------
    search.addEventListener("keydown", (e) => {
      if (e.key !== "Enter") return;
      const q = (search.value || "").trim().toLowerCase();
      if (!q) return;

      // match by key OR label includes
      let target = tileByKey.get(q);
      if (!target) {
        for (const [k, mesh] of tileByKey.entries()) {
          const label = (mesh.userData.label || "").toLowerCase();
          if (k.includes(q) || label.includes(q)) { target = mesh; break; }
        }
      }
      if (!target) {
        statusEl.textContent = `No module matched: "${search.value}"`;
        return;
      }

      // Smooth focus: move controls target + camera a bit
      const p = target.position.clone();
      controls.target.copy(p);

      // Move camera relative to target
      camera.position.set(p.x + 120, 120, p.z + 180);
      camera.lookAt(p);

      statusEl.textContent = `Focused: ${target.userData.label}`;
    });

    // ---------------------------------------
    // Helpers: sprites (label text)
    // ---------------------------------------
    function makeLabelSprite(text, group) {
      const canvas = document.createElement("canvas");
      const ctx = canvas.getContext("2d");

      canvas.width = 1024;
      canvas.height = 256;

      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.font = "bold 64px system-ui, Arial";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillStyle = "rgba(255,255,255,0.95)";
      ctx.fillText(text, canvas.width / 2, canvas.height / 2);

      const texture = new THREE.CanvasTexture(canvas);
      const mat = new THREE.SpriteMaterial({ map: texture, transparent: true });
      const sprite = new THREE.Sprite(mat);
      sprite.scale.set(120, 30, 1);
      return sprite;
    }

    function makeSmallSprite(text, alpha=0.9) {
      const canvas = document.createElement("canvas");
      const ctx = canvas.getContext("2d");

      canvas.width = 512;
      canvas.height = 128;

      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.font = "bold 44px system-ui, Arial";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillStyle = `rgba(220,235,255,${alpha})`;
      ctx.fillText(text, canvas.width / 2, canvas.height / 2);

      const texture = new THREE.CanvasTexture(canvas);
      const mat = new THREE.SpriteMaterial({ map: texture, transparent: true });
      const sprite = new THREE.Sprite(mat);
      sprite.scale.set(86, 22, 1);
      return sprite;
    }

    // Render loop
    function animate() {
      requestAnimationFrame(animate);
      controls.update();
      renderer.render(scene, camera);
    }
    animate();

    window.addEventListener("resize", () => {
      camera.aspect = window.innerWidth / window.innerHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(window.innerWidth, window.innerHeight);
    });

    loadStats();
  </script>
</body>
</html>
