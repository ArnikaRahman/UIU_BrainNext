<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/layout.php";

$stats    = $_SESSION["student_stats"] ?? [];
$enrolled = $_SESSION["student_enrollments"] ?? [];
$hasEnroll = !empty($enrolled);

/* ---------- numbers ---------- */
$practice_submissions = (int)($stats["practice_submissions"] ?? 0);
$practice_solved      = (int)($stats["practice_solved"] ?? 0);

$total_test_sub       = (int)($stats["total_test_submissions"] ?? 0);
$pending_test_sub     = (int)($stats["pending_test_submissions"] ?? 0);
$checked_test_sub     = (int)($stats["checked_test_submissions"] ?? 0);
$pending_not_submit   = (int)($stats["pending_tests_not_submitted"] ?? 0);
$total_test_score     = (int)($stats["total_test_score"] ?? 0);

$rank = (int)($stats["rank"] ?? 0);
$tot  = (int)($stats["total_students"] ?? 0);

ui_start("Student Dashboard", "Student Panel");

/**
 * ✅ Inject into dropdown (layout.php handles rendering)
 */
$actions = [
  ["Open Problems", "/student/problems.php"],
  ["My Submissions", "/student/submissions.php"],
  ["Tests", "/student/student_tests.php"],
];

if ($hasEnroll) {
  $actions[] = ["Course Practice", "/student/course_practice.php"];
}

ui_top_actions($actions);
?>

<style>
/* Toggle UI */
.stat-toggle-wrap{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  align-items:center;
  margin-top:12px;
}
.stat-toggle{
  display:inline-flex;
  gap:8px;
  align-items:center;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.06);
  border-radius:999px;
  padding:6px;
}
.stat-toggle button{
  border:0;
  cursor:pointer;
  padding:8px 14px;
  border-radius:999px;
  font-weight:900;
  background:transparent;
  color:inherit;
  opacity:.78;
}
.stat-toggle button.active{
  background:rgba(80,140,255,.22);
  border:1px solid rgba(80,140,255,.35);
  opacity:1;
}
.stat-kpis{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:8px 14px;
  margin-top:10px;
}
.stat-kpis .kpi{
  padding:10px 12px;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(10,15,25,.20);
}
.stat-kpis .kpi .label{opacity:.75;font-size:13px;}
.stat-kpis .kpi .val{font-weight:900;font-size:18px;margin-top:4px;}
@media(max-width: 900px){
  .stat-kpis{grid-template-columns:1fr;}
}

/* ✅ fixed-size chart */
.chart-box{
  width: 360px;
  max-width: 100%;
  aspect-ratio: 1 / 1;
  margin: 14px auto 0 auto;
  position: relative;
}
.chart-box canvas{
  width: 100% !important;
  height: 100% !important;
  display:block;
}

/* Legend */
.legend-grid{
  margin-top:12px;
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:8px 12px;
}
@media(max-width: 1100px){
  .legend-grid{grid-template-columns:1fr;}
}
.legend-item{
  display:flex;
  gap:10px;
  align-items:center;
  padding:8px 10px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(10,15,25,.18);
}
.legend-dot{
  width:12px;height:12px;border-radius:4px;
  border:1px solid rgba(255,255,255,.18);
  flex:0 0 auto;
}
.legend-text{
  display:flex;
  justify-content:space-between;
  gap:10px;
  width:100%;
  font-size:13px;
}
.legend-text .name{opacity:.9;}
.legend-text .num{font-weight:900;opacity:.95;}
</style>

<div class="grid">
  <div class="card col-12">
    <h3>My Stats</h3>
    <div class="muted">Visual distribution of your activity.</div>

    <div class="stat-toggle-wrap">
      <div class="stat-toggle" role="tablist" aria-label="Stats mode">
        <button type="button" id="btnPractice" class="active" onclick="setMode('practice')">Practice</button>
        <button type="button" id="btnTests" onclick="setMode('tests')">Tests</button>
      </div>
      <div class="muted" id="modeHint">Practice stats overview.</div>
    </div>

    <div class="stat-kpis" id="kpiPractice">
      <div class="kpi">
        <div class="label">Practice submissions</div>
        <div class="val"><?= $practice_submissions ?></div>
      </div>
      <div class="kpi">
        <div class="label">Practice solved</div>
        <div class="val"><?= $practice_solved ?></div>
      </div>
    </div>

    <div class="stat-kpis" id="kpiTests" style="display:none;">
      <div class="kpi">
        <div class="label">Tests submitted</div>
        <div class="val"><?= $total_test_sub ?></div>
      </div>
      <div class="kpi">
        <div class="label">Test pending check</div>
        <div class="val"><?= $pending_test_sub ?></div>
      </div>
      <div class="kpi">
        <div class="label">Test checked</div>
        <div class="val"><?= $checked_test_sub ?></div>
      </div>
      <div class="kpi">
        <div class="label">Pending tests (not submitted)</div>
        <div class="val"><?= $pending_not_submit ?></div>
      </div>
      <div class="kpi">
        <div class="label">Total test score</div>
        <div class="val"><?= $total_test_score ?></div>
      </div>
      <div class="kpi">
        <div class="label">Leaderboard rank (tests)</div>
        <div class="val"><?= $rank > 0 ? ($rank . " / " . $tot) : "-" ?></div>
      </div>
    </div>

    <div class="chart-box">
      <canvas id="statsChart"></canvas>
    </div>

    <div class="legend-grid" id="chartLegend"></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
const DATA = {
  practice: {
    title: "Practice stats overview.",
    labels: ["Practice Submissions", "Practice Solved"],
    values: [<?= (int)$practice_submissions ?>, <?= (int)$practice_solved ?>]
  },
  tests: {
    title: "Tests stats overview.",
    labels: [
      "Tests Submitted",
      "Test Pending Check",
      "Test Checked",
      "Pending Tests (Not Submitted)",
      "Total Test Score",
      "Leaderboard Rank"
    ],
    values: [
      <?= (int)$total_test_sub ?>,
      <?= (int)$pending_test_sub ?>,
      <?= (int)$checked_test_sub ?>,
      <?= (int)$pending_not_submit ?>,
      <?= (int)$total_test_score ?>,
      <?= (int)($rank > 0 ? $rank : 0) ?>
    ]
  }
};

let mode = "practice";
let chart = null;

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, (m) => ({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
  }[m]));
}

function buildLegend(labels, values){
  const el = document.getElementById("chartLegend");
  el.innerHTML = "";
  const colors = chart?.data?.datasets?.[0]?.backgroundColor || [];
  labels.forEach((name, i) => {
    const item = document.createElement("div");
    item.className = "legend-item";
    const dot = document.createElement("div");
    dot.className = "legend-dot";
    dot.style.background = colors[i] || "rgba(255,255,255,.35)";
    const text = document.createElement("div");
    text.className = "legend-text";
    text.innerHTML = `<span class="name">${escapeHtml(name)}</span><span class="num">${values[i] ?? 0}</span>`;
    item.appendChild(dot);
    item.appendChild(text);
    el.appendChild(item);
  });
}

function renderChart(){
  const ctx = document.getElementById("statsChart");
  const labels = DATA[mode].labels;
  const values = DATA[mode].values;

  const sum = values.reduce((a,b)=>a+(Number(b)||0),0);
  const finalLabels = sum > 0 ? labels : ["No data"];
  const finalValues = sum > 0 ? values : [1];

  if (chart) chart.destroy();

  chart = new Chart(ctx, {
    type: "doughnut",
    data: { labels: finalLabels, datasets: [{ data: finalValues, borderWidth: 1 }] },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 1,
      cutout: "62%",
      layout: { padding: 6 },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(c){
              const name = c.label || "";
              const val = c.parsed || 0;
              if (name === "No data") return "No data available";
              return `${name}: ${val}`;
            }
          }
        }
      }
    }
  });

  buildLegend(finalLabels, finalValues);
}

function setMode(m){
  mode = m;
  document.getElementById("btnPractice").classList.toggle("active", m === "practice");
  document.getElementById("btnTests").classList.toggle("active", m === "tests");
  document.getElementById("kpiPractice").style.display = (m === "practice") ? "" : "none";
  document.getElementById("kpiTests").style.display = (m === "tests") ? "" : "none";
  document.getElementById("modeHint").textContent = DATA[m].title;
  renderChart();
}

setMode("practice");
</script>

<?php ui_end(); ?>



















