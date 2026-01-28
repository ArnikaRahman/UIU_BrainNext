<?php
require_once __DIR__ . "/../includes/auth_admin.php";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>3D Schema - UIU BrainNext</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    html, body { margin:0; height:100%; overflow:hidden; font-family: system-ui, Arial; }

    #hud{
      position:fixed; top:12px; left:12px; z-index:10;
      background:rgba(0,0,0,.65); color:#fff;
      padding:10px 12px; border-radius:12px; font-size:14px;
      max-width:420px;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }
    #row { display:flex; gap:10px; align-items:center; margin-top:8px; flex-wrap:wrap; }
    select {
      padding:6px 10px;
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
      padding:6px 12px;
      border-radius:10px;
      border: 1px solid rgba(255,255,255,.18);
    }

    #tip{
      position:fixed; pointer-events:none; z-index:20;
      background:rgba(20,20,20,.92); color:#fff;
      padding:10px 12px; border-radius:10px; font-size:13px;
      transform: translate(12px, 12px);
      display:none; white-space:pre; max-width:520px;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }
    #legend{ margin-top:10px; font-size:12px; opacity:.9; line-height:1.35; }

    /* ✅ Right panel */
    #panel{
      position:fixed; top:0; right:0; height:100%; width:420px;
      background:rgba(6,10,22,.92);
      border-left:1px solid rgba(255,255,255,.10);
      box-shadow: -10px 0 40px rgba(0,0,0,.45);
      z-index:30;
      transform: translateX(110%);
      transition: transform .18s ease;
      overflow:auto;
      color:#fff;
      backdrop-filter: blur(10px);
    }
    #panel.open{ transform: translateX(0); }

    #panel .ph{
      position:sticky; top:0;
      padding:14px 14px 12px;
      background:rgba(6,10,22,.92);
      border-bottom:1px solid rgba(255,255,255,.10);
      display:flex; align-items:flex-start; justify-content:space-between; gap:12px;
      z-index:2;
    }
    #panel .ph .tname{ font-weight:900; font-size:16px; line-height:1.15; }
    #panel .ph .sub{ opacity:.85; font-size:12px; margin-top:4px; }
    #panel .close{
      cursor:pointer;
      padding:6px 10px;
      border-radius:10px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(255,255,255,.08);
      color:#fff;
      font-weight:700;
    }
    #panel .pc{ padding:14px; }

    .sec{ margin-bottom:14px; }
    .sec h4{ margin:0 0 8px; font-size:13px; letter-spacing:.2px; opacity:.95; }
    .box{
      border:1px solid rgba(255,255,255,.10);
      background:rgba(255,255,255,.05);
      border-radius:14px;
      padding:10px;
      overflow:auto;
    }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; font-size:12px; }

    table.t{
      width:100%;
      border-collapse: collapse;
      font-size:12px;
    }
    table.t th, table.t td{
      border-bottom:1px solid rgba(255,255,255,.08);
      padding:8px 6px;
      vertical-align:top;
      text-align:left;
    }
    table.t th{ opacity:.85; font-weight:800; }
    .pill{
      display:inline-block;
      padding:2px 8px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(255,255,255,.06);
      font-size:11px;
      margin-right:6px;
      margin-bottom:6px;
    }
    .btn2{
      display:inline-block;
      margin-top:10px;
      text-decoration:none;
      color:#fff;
      font-weight:800;
      padding:8px 12px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(255,255,255,.08);
    }
    .muted{ opacity:.8; }
  </style>
</head>
<body>
  <div id="hud">
    <div><b>3D Database Schema</b></div>
    <div id="status">Loading schema…</div>

    <div id="row">
      <label for="dbSel">Database:</label>
      <select id="dbSel">
        <option value="uiu_brainnext">uiu_brainnext</option>
        <option value="uiu_brainnext_meta">uiu_brainnext_meta</option>
      </select>

      <a class="btn" href="/uiu_brainnext/admin/dashboard.php">Back</a>
    </div>

    <div id="legend">
      • Drag: rotate &nbsp; • Scroll: zoom &nbsp; • Right drag: pan<br>
      • Lines = FK relations<br>
      • Click a table to open details (columns, FK, indexes, sample rows)
    </div>
  </div>

  <div id="tip"></div>

  <!-- ✅ Right side panel -->
  <aside id="panel" aria-hidden="true">
    <div class="ph">
      <div>
        <div class="tname" id="pTitle">Table</div>
        <div class="sub" id="pSub">—</div>
      </div>
      <button class="close" id="pClose" type="button">Close</button>
    </div>
    <div class="pc" id="pBody">
      <div class="box muted">Click a table to load details…</div>
    </div>
  </aside>

  <!-- ✅ Stable non-module Three.js (OrbitControls works) -->
  <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>

  <script>
    // ========= CONFIG =========
    const HIGHLIGHT_TABLE = "submissions"; // highlight color

    const COLOR_NORMAL    = 0x2f6fed;
    const COLOR_HIGHLIGHT = 0xf59e0b;
    const COLOR_LINES     = 0x86a2ff;

    // ========= DOM =========
    const statusEl = document.getElementById("status");
    const tip = document.getElementById("tip");
    const dbSel = document.getElementById("dbSel");

    const panel = document.getElementById("panel");
    const pTitle = document.getElementById("pTitle");
    const pSub   = document.getElementById("pSub");
    const pBody  = document.getElementById("pBody");
    const pClose = document.getElementById("pClose");

    pClose.addEventListener("click", () => closePanel());
    window.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closePanel();
    });

    const params = new URLSearchParams(location.search);
    const initialDb = params.get("db") || "uiu_brainnext";
    dbSel.value = initialDb;

    // ========= THREE STATE =========
    let scene, camera, renderer, controls;
    let raycaster, mouse;
    let objects = [];
    let tableMeshes = new Map();
    let hovered = null;
    let animId = null;

    // ========= MAIN =========
    dbSel.addEventListener("change", () => {
      const db = dbSel.value;
      const u = new URL(location.href);
      u.searchParams.set("db", db);
      history.replaceState({}, "", u.toString());
      closePanel();
      loadAndRender(db);
    });

    window.addEventListener("resize", () => {
      if (!camera || !renderer) return;
      camera.aspect = window.innerWidth / window.innerHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(window.innerWidth, window.innerHeight);
    });

    loadAndRender(dbSel.value);

    async function loadAndRender(db) {
      statusEl.textContent = "Loading schema for " + db + "…";
      cleanup();

      const schema = await fetch("schema_api.php?db=" + encodeURIComponent(db)).then(r => r.json());
      if (schema.error) {
        statusEl.textContent = "Error: " + schema.error;
        return;
      }

      initScene();
      buildSchema(schema);

      statusEl.textContent = "Hover a table to see columns. Click a table to open details.";
      animate();
    }

    function cleanup() {
      if (animId) cancelAnimationFrame(animId);

      hovered = null;
      objects = [];
      tableMeshes = new Map();

      if (renderer && renderer.domElement && renderer.domElement.parentNode) {
        renderer.domElement.parentNode.removeChild(renderer.domElement);
      }
      renderer = null;
      scene = null;
      camera = null;
      controls = null;
      raycaster = null;
      mouse = null;

      tip.style.display = "none";
    }

    function initScene() {
      scene = new THREE.Scene();
      scene.background = new THREE.Color(0x070b18);
      scene.fog = new THREE.Fog(0x070b18, 140, 520);

      camera = new THREE.PerspectiveCamera(55, window.innerWidth / window.innerHeight, 0.1, 2000);
      camera.position.set(0, 120, 220);
      camera.lookAt(0, 0, 0);

      renderer = new THREE.WebGLRenderer({ antialias: true });
      renderer.setSize(window.innerWidth, window.innerHeight);
      renderer.setPixelRatio(window.devicePixelRatio || 1);
      document.body.appendChild(renderer.domElement);

      controls = new THREE.OrbitControls(camera, renderer.domElement);
      controls.enableDamping = true;
      controls.dampingFactor = 0.08;
      controls.rotateSpeed = 0.6;
      controls.zoomSpeed = 0.9;
      controls.panSpeed = 0.6;
      controls.minDistance = 80;
      controls.maxDistance = 520;

      scene.add(new THREE.AmbientLight(0xffffff, 0.60));
      const dir = new THREE.DirectionalLight(0xffffff, 0.95);
      dir.position.set(80, 120, 60);
      scene.add(dir);

      const rim = new THREE.DirectionalLight(0x9fb5ff, 0.35);
      rim.position.set(-120, 60, -90);
      scene.add(rim);

      const grid = new THREE.GridHelper(900, 70, 0x233055, 0x141c33);
      grid.position.y = -18;
      grid.material.opacity = 0.45;
      grid.material.transparent = true;
      scene.add(grid);

      raycaster = new THREE.Raycaster();
      mouse = new THREE.Vector2();

      window.onmousemove = onMouseMovePick;
      window.onclick = onClick;
    }

    function buildSchema(schema) {
      const boxGeo = new THREE.BoxGeometry(26, 12, 18);

      const R = 140;
      const count = schema.tables.length || 1;

      schema.tables.forEach((t, i) => {
        const angle = (i / count) * Math.PI * 2;
        const x = Math.cos(angle) * R;
        const z = Math.sin(angle) * R;

        const isHighlight = (t.name === HIGHLIGHT_TABLE);

        const mat = new THREE.MeshStandardMaterial({
          color: isHighlight ? COLOR_HIGHLIGHT : COLOR_NORMAL,
          metalness: 0.25,
          roughness: 0.35,
          emissive: new THREE.Color(0x0b1638),
          emissiveIntensity: 1.0
        });

        const mesh = new THREE.Mesh(boxGeo, mat);
        mesh.position.set(x, 0, z);
        mesh.userData = t; // contains {name, columns...}
        scene.add(mesh);

        const label = makeLabelSprite(t.name, isHighlight);
        label.position.set(x, 12, z);
        scene.add(label);

        tableMeshes.set(t.name, mesh);
        objects.push(mesh);
      });

      const lineGroup = new THREE.Group();
      scene.add(lineGroup);

      (schema.relations || []).forEach(r => {
        const a = tableMeshes.get(r.from);
        const b = tableMeshes.get(r.to);
        if (!a || !b) return;

        const pts = [
          new THREE.Vector3(a.position.x, 0, a.position.z),
          new THREE.Vector3((a.position.x + b.position.x)/2, 28, (a.position.z + b.position.z)/2),
          new THREE.Vector3(b.position.x, 0, b.position.z)
        ];

        const curve = new THREE.QuadraticBezierCurve3(pts[0], pts[1], pts[2]);
        const curvePts = curve.getPoints(60);

        const geom = new THREE.BufferGeometry().setFromPoints(curvePts);
        const mat = new THREE.LineBasicMaterial({
          color: COLOR_LINES,
          transparent: true,
          opacity: 0.75
        });
        lineGroup.add(new THREE.Line(geom, mat));
      });
    }

    function makeLabelSprite(text, isHighlight) {
      const canvas = document.createElement("canvas");
      const ctx = canvas.getContext("2d");
      canvas.width = 512;
      canvas.height = 128;

      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.font = "bold 64px system-ui, Arial";
      ctx.fillStyle = isHighlight ? "rgba(255,220,160,0.98)" : "rgba(255,255,255,0.95)";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText(text, canvas.width / 2, canvas.height / 2);

      const texture = new THREE.CanvasTexture(canvas);
      const material = new THREE.SpriteMaterial({ map: texture, transparent: true });
      const sprite = new THREE.Sprite(material);
      sprite.scale.set(54, 13, 1);
      return sprite;
    }

    function onMouseMovePick(e) {
      if (!raycaster || !camera) return;

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

      const cols = (hovered.userData.columns || []).slice(0, 28).join("\n• ");
      tip.textContent =
        hovered.userData.name +
        "\n\n• " + cols +
        ((hovered.userData.columns || []).length > 28 ? "\n• ..." : "") +
        "\n\n(click to open details)";

      tip.style.display = "block";
    }

    async function onClick() {
      if (!hovered) return;
      const table = (hovered.userData && hovered.userData.name) ? hovered.userData.name : "";
      if (!table) return;

      await openPanelFor(table);
    }

    function esc(s){
      return String(s ?? "").replace(/[&<>"']/g, (m) => ({
        "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
      }[m]));
    }

    function closePanel(){
      panel.classList.remove("open");
      panel.setAttribute("aria-hidden","true");
    }

    async function openPanelFor(table){
      const db = dbSel.value;

      panel.classList.add("open");
      panel.setAttribute("aria-hidden","false");

      pTitle.textContent = table;
      pSub.textContent = "Loading…";
      pBody.innerHTML = `<div class="box muted">Loading table info…</div>`;

      try{
        const url = "schema_table_info.php?db=" + encodeURIComponent(db) + "&table=" + encodeURIComponent(table);
        const j = await fetch(url).then(r => r.json());

        if (j.error){
          pSub.textContent = "Error";
          pBody.innerHTML = `<div class="box mono">${esc(j.error)}</div>`;
          return;
        }

        pSub.textContent = `DB: ${j.db} • Rows: ${j.row_count ?? "?"}`;

        // Columns table
        const colRows = (j.columns || []).map(c => `
          <tr>
            <td class="mono">${esc(c.name)}</td>
            <td class="mono">${esc(c.type)}</td>
            <td class="mono">${esc(c.nullable)}</td>
            <td class="mono">${esc(c.key)}</td>
            <td class="mono">${esc(c.default)}</td>
            <td class="mono">${esc(c.extra)}</td>
          </tr>
        `).join("");

        const colsHTML = `
          <div class="sec">
            <h4>Columns</h4>
            <div class="box">
              <table class="t">
                <thead>
                  <tr>
                    <th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th>
                  </tr>
                </thead>
                <tbody>${colRows || `<tr><td colspan="6" class="muted">No columns found</td></tr>`}</tbody>
              </table>
            </div>
          </div>
        `;

        // Indexes
        const idxHTML = (() => {
          const idx = j.indexes || [];
          if (!idx.length) return `
            <div class="sec">
              <h4>Indexes</h4>
              <div class="box muted">No indexes found</div>
            </div>
          `;
          const rows = idx.map(x => `
            <div class="pill mono">${esc(x.name)}: ${esc(x.columns)}</div>
          `).join("");
          return `
            <div class="sec">
              <h4>Indexes</h4>
              <div class="box">${rows}</div>
            </div>
          `;
        })();

        // Foreign keys
        const fkHTML = (() => {
          const fk = j.foreign_keys || [];
          if (!fk.length) return `
            <div class="sec">
              <h4>Foreign Keys</h4>
              <div class="box muted">No foreign keys found</div>
            </div>
          `;
          const rows = fk.map(x => `
            <div class="box mono" style="margin-bottom:8px;">
              ${esc(x.from_table)}.${esc(x.from_column)} → ${esc(x.to_table)}.${esc(x.to_column)}<br>
              <span class="muted">ON DELETE:</span> ${esc(x.on_delete)} &nbsp; | &nbsp;
              <span class="muted">ON UPDATE:</span> ${esc(x.on_update)}
            </div>
          `).join("");
          return `
            <div class="sec">
              <h4>Foreign Keys</h4>
              <div>${rows}</div>
            </div>
          `;
        })();

        // Sample rows
        const sampleHTML = (() => {
          const rows = j.sample_rows || [];
          const cols = j.sample_cols || [];
          if (!rows.length || !cols.length) return `
            <div class="sec">
              <h4>Sample rows (Top 5)</h4>
              <div class="box muted">No data (or no permission)</div>
            </div>
          `;
          const thead = cols.map(c => `<th class="mono">${esc(c)}</th>`).join("");
          const body = rows.map(r => {
            const tds = cols.map(c => `<td class="mono">${esc(r[c])}</td>`).join("");
            return `<tr>${tds}</tr>`;
          }).join("");
          return `
            <div class="sec">
              <h4>Sample rows (Top 5)</h4>
              <div class="box">
                <table class="t">
                  <thead><tr>${thead}</tr></thead>
                  <tbody>${body}</tbody>
                </table>
              </div>
            </div>
          `;
        })();

        const openLink = `schema_table_view.php?db=${encodeURIComponent(j.db)}&table=${encodeURIComponent(j.table)}`;

        pBody.innerHTML = `
          ${colsHTML}
          ${idxHTML}
          ${fkHTML}
          ${sampleHTML}
          <a class="btn2" href="${openLink}">Open full table data</a>
        `;

      } catch (err){
        pSub.textContent = "Error";
        pBody.innerHTML = `<div class="box mono">Failed to load table info.</div>`;
      }
    }

    function animate() {
      animId = requestAnimationFrame(animate);
      controls.update();
      renderer.render(scene, camera);
    }
  </script>
</body>
</html>






