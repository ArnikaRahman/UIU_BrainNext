<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------------- DB helpers (✅ added) ---------------- */
function db_current_name(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db");
  $row = $r ? $r->fetch_assoc() : null;
  return (string)($row["db"] ?? "");
}
function db_has_col(mysqli $conn, string $table, string $col): bool {
  $db = db_current_name($conn);
  if ($db === "") return false;

  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME   = ?
      AND COLUMN_NAME  = ?
    LIMIT 1
  ");
  if (!$st) return false;

  $st->bind_param("sss", $db, $table, $col);
  $st->execute();
  $res = $st->get_result();
  return (bool)$res->fetch_row();
}
function pick_first_col(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (db_has_col($conn, $table, $c)) return $c;
  }
  return null;
}

/* ---------------- page helpers ---------------- */
function clamp_int($v, $min, $max) {
  $n = (int)$v;
  if ($n < $min) return $min;
  if ($n > $max) return $max;
  return $n;
}

$days  = clamp_int($_GET["days"] ?? 7, 1, 365);
$since = date("Y-m-d H:i:s", time() - ($days * 86400));

/* ---------------- schema detect (✅ fixed) ---------------- */
$time_col = pick_first_col($conn, "submissions", ["submitted_at", "created_at", "submitted_time"]);
$has_verdict = db_has_col($conn, "submissions", "verdict");

$time_filter_sql = "";
if ($time_col) {
  $time_filter_sql = " WHERE s.`$time_col` >= ? ";
}

$verdict_expr = $has_verdict
  ? "UPPER(COALESCE(s.verdict, 'MANUAL'))"
  : "'MANUAL'";

/* ---------------- query ---------------- */
$sql = "
  SELECT
    c.id AS course_id,
    c.code AS course_code,
    c.title AS course_title,
    $verdict_expr AS verdict,
    COUNT(*) AS cnt
  FROM submissions s
  JOIN problems p ON p.id = s.problem_id
  JOIN courses  c ON c.id = p.course_id
  $time_filter_sql
  GROUP BY c.id, c.code, c.title, verdict
  ORDER BY c.code ASC
";

$st = $conn->prepare($sql);
if ($time_col) {
  $st->bind_param("s", $since);
}
$st->execute();
$res = $st->get_result();

/* reshape results -> per course */
$courses = [];
$verdictKeys = ["AC","WA","CE","RE","TLE","MANUAL"];

while ($row = $res->fetch_assoc()) {
  $cid = (int)$row["course_id"];
  if (!isset($courses[$cid])) {
    $courses[$cid] = [
      "course_id" => $cid,
      "course_code" => (string)$row["course_code"],
      "course_title" => (string)$row["course_title"],
      "total" => 0,
      "counts" => array_fill_keys($verdictKeys, 0),
    ];
  }
  $v = strtoupper((string)$row["verdict"]);
  if (!in_array($v, $verdictKeys, true)) $v = "MANUAL";

  $cnt = (int)$row["cnt"];
  $courses[$cid]["counts"][$v] += $cnt;
  $courses[$cid]["total"] += $cnt;
}

$data = array_values($courses);

$maxTotal = 0;
$totalAll = 0;
foreach ($data as $c) {
  $totalAll += (int)$c["total"];
  if ((int)$c["total"] > $maxTotal) $maxTotal = (int)$c["total"];
}

ui_start("3D Analytics", "Teacher Panel");
ui_top_actions([
  ["Dashboard", "/teacher/dashboard.php"],
  ["Check Submissions", "/teacher/teacher_check_submissions.php"],
  ["3D Analytics", "/teacher/analytics3d.php"],
]);
?>

<style>
#vizWrap{
  height: 520px;
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(10,15,25,.35);
  overflow: hidden;
  position: relative;
}
#tooltip{
  position:absolute;
  z-index: 5;
  pointer-events:none;
  display:none;
  max-width: 360px;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(10,18,30,.92);
  box-shadow: 0 14px 50px rgba(0,0,0,.55);
  font-size: 13px;
}
#tooltip .t1{font-weight:900; margin-bottom:6px;}
#tooltip .mono{
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
  font-size:12px; opacity:.9;
}
.krow{display:flex; justify-content:space-between; gap:10px;}
.smallmono{
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
}
</style>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start;">
    <div>
      <h3 style="margin-bottom:6px;">3D Submission Analytics</h3>
      <div class="muted">Real DB data rendered as 3D bars (hover a bar to see details).</div>
      <div class="muted smallmono" style="margin-top:6px;">
        Window: last <?= (int)$days ?> days • Since: <?= e($since) ?>
        <?php if (!$time_col): ?>
          • <span class="muted">(No time column found in submissions; showing all-time)</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="padding:12px 14px; min-width:220px;">
      <div class="muted">Total submissions (window)</div>
      <div style="font-weight:900; font-size:18px;"><?= (int)$totalAll ?></div>

      <div style="height:10px;"></div>

      <form method="GET" style="display:flex; gap:10px; align-items:flex-end;">
        <div style="flex:1;">
          <label class="label">Days</label>
          <select name="days">
            <?php foreach ([7,14,30,60,90,180,365] as $d): ?>
              <option value="<?= (int)$d ?>" <?= $days===$d?"selected":"" ?>><?= (int)$d ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="badge" type="submit">Reload</button>
      </form>
    </div>
  </div>

  <div style="height:14px;"></div>

  <?php if (empty($data)): ?>
    <div class="alert err">
      No submissions found<?= $time_col ? " in the last " . (int)$days . " days" : "" ?>.
      <div class="muted">Try selecting a bigger day range (30/90/365).</div>
    </div>
  <?php endif; ?>

  <div id="vizWrap">
    <div id="tooltip"></div>
  </div>
</div>

<script>
window.__ANALYTICS3D__ = <?= json_encode([
  "days" => $days,
  "since" => $since,
  "maxTotal" => $maxTotal,
  "items" => $data,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<!-- ✅ IMPORTANT:
     1) Do NOT include three.min.js anywhere on this page.
     2) This importmap makes "three" resolvable for OrbitControls. -->
<script type="importmap">
{
  "imports": {
    "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
    "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
  }
}
</script>

<script type="module">
import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";

const wrap = document.getElementById("vizWrap");
const tooltip = document.getElementById("tooltip");
const DATA = window.__ANALYTICS3D__ || { items: [], maxTotal: 1 };

if (!wrap) throw new Error("vizWrap not found");

const scene = new THREE.Scene();
scene.background = new THREE.Color(0x0a0f18);

const camera = new THREE.PerspectiveCamera(55, wrap.clientWidth / wrap.clientHeight, 0.1, 2000);
camera.position.set(14, 18, 22);

const renderer = new THREE.WebGLRenderer({ antialias: true });
renderer.setSize(wrap.clientWidth, wrap.clientHeight);
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
wrap.appendChild(renderer.domElement);

// lights
scene.add(new THREE.AmbientLight(0xffffff, 0.65));
const dir = new THREE.DirectionalLight(0xffffff, 0.9);
dir.position.set(10, 18, 12);
scene.add(dir);

// grid
const grid = new THREE.GridHelper(60, 60, 0x334155, 0x1f2a3a);
scene.add(grid);

// controls ✅
const controls = new OrbitControls(camera, renderer.domElement);
controls.enableDamping = true;
controls.dampingFactor = 0.08;
controls.target.set(0, 4, 0);
controls.update();

// bars
const bars = [];
const items = Array.isArray(DATA.items) ? DATA.items : [];
const maxTotal = Math.max(1, Number(DATA.maxTotal || 1));

const spacing = 2.2;
const startX = -((items.length - 1) * spacing) / 2;

items.forEach((it, idx) => {
  const total = Number(it.total || 0);
  const h = 1 + (total / maxTotal) * 10;

  const geom = new THREE.BoxGeometry(1.2, h, 1.2);
  const mat = new THREE.MeshStandardMaterial({
    color: 0x3b82f6,
    roughness: 0.35,
    metalness: 0.08,
    emissive: 0x000000
  });
  const mesh = new THREE.Mesh(geom, mat);

  mesh.position.set(startX + idx * spacing, h / 2, 0);
  mesh.userData = it;
  scene.add(mesh);
  bars.push(mesh);
});

// hover
const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();

function showTip(x, y, it){
  const counts = it.counts || {};
  const lines = ["AC","WA","CE","RE","TLE","MANUAL"].map(k => {
    const v = Number(counts[k] || 0);
    return `<div class="krow"><div class="mono">${k}</div><div class="mono">${v}</div></div>`;
  }).join("");

  tooltip.innerHTML = `
    <div class="t1">${(it.course_code || "")} • ${(it.course_title || "")}</div>
    <div class="mono">Total: ${Number(it.total || 0)}</div>
    <div style="height:8px;"></div>
    ${lines}
  `;
  tooltip.style.left = (x + 14) + "px";
  tooltip.style.top  = (y + 14) + "px";
  tooltip.style.display = "block";
}
function hideTip(){ tooltip.style.display = "none"; }

wrap.addEventListener("mousemove", (e) => {
  const rect = wrap.getBoundingClientRect();
  const x = e.clientX - rect.left;
  const y = e.clientY - rect.top;

  mouse.x = (x / rect.width) * 2 - 1;
  mouse.y = -(y / rect.height) * 2 + 1;

  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObjects(bars, false);

  bars.forEach(b => b.material.emissive.setHex(0x000000));

  if (hits.length) {
    const obj = hits[0].object;
    showTip(x, y, obj.userData || {});
    obj.material.emissive.setHex(0x0b2a55);
  } else {
    hideTip();
  }
});

wrap.addEventListener("mouseleave", () => {
  hideTip();
  bars.forEach(b => b.material.emissive.setHex(0x000000));
});

// resize
window.addEventListener("resize", () => {
  const w = wrap.clientWidth;
  const h = wrap.clientHeight;
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
  renderer.setSize(w, h);
});

// loop
function animate(){
  controls.update();
  renderer.render(scene, camera);
  requestAnimationFrame(animate);
}
animate();
</script>

<?php ui_end(); ?>









