<?php
// /uiu_brainnext/admin/analytics3d.php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";

if (session_status() === PHP_SESSION_NONE) session_start();

ui_start("3D Analytics", "Admin Panel");
?>
<style>
#wrap3d{
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(10,15,25,.25);
  overflow: hidden;
  position: relative;
  min-height: 520px;
}
#hud{
  position:absolute;
  inset: 12px 12px auto auto;
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  align-items:center;
  justify-content:flex-end;
  z-index:5;
}
.hudbox{
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(10,16,26,.72);
  backdrop-filter: blur(10px);
  padding: 10px 12px;
  display:flex;
  gap:10px;
  align-items:center;
}
#tooltip{
  position:absolute;
  display:none;
  z-index:10;
  pointer-events:none;
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,16,26,.90);
  max-width: 320px;
}
#tooltip .t1{font-weight:900; margin-bottom:6px;}
#tooltip .t2{opacity:.85; font-size:12px; line-height:1.35;}
.smallmono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px;}
.badgebtn{
  cursor:pointer;
  border:1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.06);
  border-radius: 999px;
  padding: 7px 12px;
  font-weight: 900;
  color: #fff;
}
select, input{
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.06);
  color: #fff;
  padding: 7px 10px;
}
label{font-weight:900; font-size:12px; opacity:.85;}
</style>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start;">
    <div>
      <h3 style="margin-bottom:6px;">3D Submission Analytics</h3>
      <div class="muted">
        Real data from DB → rendered as 3D bars (hover to see details).
      </div>
      <div class="muted smallmono" style="margin-top:6px;">
        Bars = total submissions per course (last N days). Hover shows AC/WA/CE/RE/TLE/MANUAL counts.
      </div>
    </div>
  </div>

  <div style="height:12px;"></div>

  <div id="wrap3d">
    <div id="hud">
      <div class="hudbox">
        <div>
          <label>Days</label><br>
          <select id="daysSel">
            <option value="3">3</option>
            <option value="7" selected>7</option>
            <option value="14">14</option>
            <option value="30">30</option>
          </select>
        </div>
        <button class="badgebtn" id="reloadBtn">Reload</button>
      </div>
    </div>

    <div id="tooltip">
      <div class="t1" id="ttTitle">—</div>
      <div class="t2 smallmono" id="ttBody">—</div>
    </div>

    <div id="canvasHost"></div>
  </div>

  <div style="height:12px;"></div>
  <div class="muted smallmono">
    Tip: Drag to rotate, scroll to zoom. Hover bars for tooltip.
  </div>
</div>

<script type="module">
import * as THREE from 'https://unpkg.com/three@0.160.0/build/three.module.js';
import { OrbitControls } from 'https://unpkg.com/three@0.160.0/examples/jsm/controls/OrbitControls.js';

const host = document.getElementById('canvasHost');
const wrap = document.getElementById('wrap3d');
const tooltip = document.getElementById('tooltip');
const ttTitle = document.getElementById('ttTitle');
const ttBody  = document.getElementById('ttBody');

let scene, camera, renderer, controls, raycaster, mouse;
let barsGroup = new THREE.Group();
let barMeshes = [];
let animId = null;

function size(){
  const r = wrap.getBoundingClientRect();
  return { w: Math.max(300, r.width), h: Math.max(420, r.height) };
}

function init3D(){
  const { w, h } = size();

  scene = new THREE.Scene();
  scene.background = new THREE.Color(0x060a12);

  camera = new THREE.PerspectiveCamera(55, w / h, 0.1, 2000);
  camera.position.set(14, 14, 18);

  renderer = new THREE.WebGLRenderer({ antialias: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.setSize(w, h);

  host.innerHTML = "";
  host.appendChild(renderer.domElement);

  controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;

  const hemi = new THREE.HemisphereLight(0xffffff, 0x223355, 1.1);
  scene.add(hemi);

  const dir = new THREE.DirectionalLight(0xffffff, 0.9);
  dir.position.set(10, 18, 12);
  scene.add(dir);

  // floor grid
  const grid = new THREE.GridHelper(60, 60, 0x1b2a44, 0x0f172a);
  grid.position.y = 0;
  scene.add(grid);

  // axis base
  const baseGeo = new THREE.BoxGeometry(60, 0.15, 60);
  const baseMat = new THREE.MeshStandardMaterial({ color: 0x0b1220, roughness: 0.9, metalness: 0.0 });
  const base = new THREE.Mesh(baseGeo, baseMat);
  base.position.y = -0.08;
  scene.add(base);

  raycaster = new THREE.Raycaster();
  mouse = new THREE.Vector2();

  scene.add(barsGroup);

  renderer.domElement.addEventListener("mousemove", onMouseMove);
  renderer.domElement.addEventListener("mouseleave", () => { tooltip.style.display = "none"; });

  animate();
}

function animate(){
  animId = requestAnimationFrame(animate);
  controls.update();
  renderer.render(scene, camera);
  handleHover();
}

function cleanupBars(){
  barMeshes = [];
  while (barsGroup.children.length) {
    const c = barsGroup.children.pop();
    c.geometry?.dispose?.();
    c.material?.dispose?.();
  }
}

function createBars(rows){
  cleanupBars();

  if (!rows || !rows.length){
    // show nothing
    return;
  }

  // layout: bars along X, centered
  const maxTotal = Math.max(...rows.map(r => r.total || 0), 1);
  const scaleY = 10 / maxTotal; // max height ≈ 10

  const gap = 1.1;
  const barW = 0.8;
  const barD = 0.8;

  const startX = -((rows.length - 1) * gap) / 2;

  rows.forEach((r, i) => {
    const h = Math.max(0.3, (r.total || 0) * scaleY);

    const geo = new THREE.BoxGeometry(barW, h, barD);
    const mat = new THREE.MeshStandardMaterial({
      color: 0x4f86ff,
      roughness: 0.65,
      metalness: 0.10
    });

    const m = new THREE.Mesh(geo, mat);
    m.position.set(startX + i * gap, h/2, 0);

    // store data for tooltip
    m.userData = r;

    barsGroup.add(m);
    barMeshes.push(m);
  });

  // move camera target nicely
  controls.target.set(0, 3.5, 0);
  controls.update();
}

async function loadData(days){
  const url = `<?php echo e(BASE_URL); ?>/api/stats_submissions.php?days=${encodeURIComponent(days)}`;
  const res = await fetch(url, { credentials: "same-origin" });
  const data = await res.json().catch(() => null);

  if (!data || !data.ok){
    alert((data && data.error) ? data.error : "Failed to load stats.");
    return [];
  }
  return data.rows || [];
}

function onMouseMove(e){
  const rect = renderer.domElement.getBoundingClientRect();
  mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -(((e.clientY - rect.top) / rect.height) * 2 - 1);

  // tooltip position (absolute in wrap)
  const wrapRect = wrap.getBoundingClientRect();
  tooltip.style.left = (e.clientX - wrapRect.left + 12) + "px";
  tooltip.style.top  = (e.clientY - wrapRect.top + 12) + "px";
}

function handleHover(){
  if (!raycaster || !barMeshes.length) return;

  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObjects(barMeshes, false);

  if (!hits.length){
    tooltip.style.display = "none";
    return;
  }

  const hit = hits[0].object;
  const r = hit.userData || {};

  // highlight
  barMeshes.forEach(b => b.material.emissive?.setHex?.(0x000000));
  if (hit.material && hit.material.emissive) hit.material.emissive.setHex(0x101820);

  const code = r.course_code || "—";
  const title = r.course_title || "";
  ttTitle.textContent = `${code}${title ? " • " + title : ""}`;

  const lines = [
    `Total: ${r.total ?? 0}`,
    `AC: ${r.ac ?? 0}   WA: ${r.wa ?? 0}`,
    `CE: ${r.ce ?? 0}   RE: ${r.re ?? 0}`,
    `TLE: ${r.tle ?? 0}  MANUAL: ${r.manual ?? 0}`,
  ];
  ttBody.textContent = lines.join("\n");

  tooltip.style.display = "block";
}

async function boot(){
  init3D();
  const days = document.getElementById("daysSel").value || "7";
  const rows = await loadData(days);
  createBars(rows);
}

document.getElementById("reloadBtn").addEventListener("click", async () => {
  const days = document.getElementById("daysSel").value || "7";
  const rows = await loadData(days);
  createBars(rows);
});

window.addEventListener("resize", () => {
  if (!renderer || !camera) return;
  const { w, h } = size();
  renderer.setSize(w, h);
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
});

boot();
</script>

<?php ui_end(); ?>