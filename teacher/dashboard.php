<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$teacher_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($teacher_id <= 0) redirect("/uiu_brainnext/logout.php");

/* ---------------- safe helpers ---------------- */
function q_count(mysqli $conn, string $sql, string $types, array $params): int {
  $st = $conn->prepare($sql);
  if (!$st) return 0;
  if ($types !== "") $st->bind_param($types, ...$params);
  if (!$st->execute()) return 0;
  $r = $st->get_result();
  $row = $r ? $r->fetch_assoc() : null;
  return (int)($row["c"] ?? 0);
}

// Column-existence helper (keeps dashboard compatible with different schemas)
function db_has_col(mysqli $conn, string $table, string $col): bool {
  $st = $conn->prepare(
    "SELECT 1
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
      LIMIT 1"
  );
  if (!$st) return false;
  $st->bind_param("ss", $table, $col);
  if (!$st->execute()) return false;
  return (bool)$st->get_result()->fetch_row();
}

/* ---------------- Teacher KPIs ---------------- */
$sections_count = q_count($conn,
  "SELECT COUNT(*) AS c FROM sections WHERE teacher_id=?",
  "i", [$teacher_id]
);

$courses_count = q_count($conn,
  "SELECT COUNT(DISTINCT course_id) AS c FROM sections WHERE teacher_id=?",
  "i", [$teacher_id]
);

$problems_count = q_count($conn,
  "SELECT COUNT(DISTINCT p.id) AS c
   FROM problems p
   JOIN sections s ON s.course_id = p.course_id
   WHERE s.teacher_id=?",
  "i", [$teacher_id]
);

$total_submissions = q_count($conn,
  "SELECT COUNT(*) AS c
   FROM submissions sub
   JOIN problems p ON p.id = sub.problem_id
   JOIN sections s ON s.course_id = p.course_id
   WHERE s.teacher_id=?",
  "i", [$teacher_id]
);

$sub_time_col = db_has_col($conn, "submissions", "submitted_at")
  ? "submitted_at"
  : (db_has_col($conn, "submissions", "created_at") ? "created_at" : "submitted_at");

$since = date("Y-m-d H:i:s", time() - 7*24*3600);
$submissions_7d = q_count($conn,
  "SELECT COUNT(*) AS c
   FROM submissions sub
   JOIN problems p ON p.id = sub.problem_id
   JOIN sections s ON s.course_id = p.course_id
   WHERE s.teacher_id=? AND sub.$sub_time_col >= ?",
  "is", [$teacher_id, $since]
);

ui_start("Teacher Dashboard", "Teacher Panel");

// Teacher quick actions will be placed inside the NAME dropdown (student-dashboard style)
$teacher_menu_items = [
  ["label" => "My Sections",          "url" => "/teacher/teacher_sections.php"],
  ["label" => "Section Performance",  "url" => "/teacher/section_performance.php"],
  ["label" => "Check Submissions",    "url" => "/teacher/teacher_check_submissions.php"],
  ["label" => "3D Analytics",         "url" => "/teacher/analytics3d.php"],
];
?>

<script>
// Convert teacher header (name + logout) into a student-like dropdown menu.
(function(){
  const BASE_URL = <?php echo json_encode(BASE_URL); ?>;
  const userName = <?php echo json_encode($_SESSION["user"]["full_name"] ?? $_SESSION["user"]["username"] ?? ""); ?>;
  const items = <?php echo json_encode($teacher_menu_items); ?>;

  function build(){
    const nav = document.querySelector('.nav');
    if (!nav) return;

    // The right-side user box is the second ".left" inside .nav
    const boxes = nav.querySelectorAll(':scope > .left');
    if (!boxes || boxes.length < 2) return;
    const rightBox = boxes[1];

    rightBox.innerHTML = `
      <div class="profile-wrap" id="tProfileWrap">
        <div class="profile-btn" id="tProfileBtn" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false">
          <span>${userName}</span>
          <span class="profile-caret">▼</span>
        </div>
        <div class="profile-menu" id="tProfileMenu" role="menu" aria-label="Teacher menu">
          <div id="tMenuInjected"></div>
          <div class="menu-sep"></div>
          <a class="danger" href="${BASE_URL}/logout.php" role="menuitem">
            <span>Logout</span><span>⎋</span>
          </a>
        </div>
      </div>
    `;

    const wrap = document.getElementById('tProfileWrap');
    const btn  = document.getElementById('tProfileBtn');
    const menu = document.getElementById('tProfileMenu');
    const inject = document.getElementById('tMenuInjected');
    if (!wrap || !btn || !menu || !inject) return;

    // Inject menu items
    (items || []).forEach(function(it){
      const a = document.createElement('a');
      a.href = BASE_URL + it.url;
      a.innerHTML = `<span>${it.label}</span><span>→</span>`;
      a.addEventListener('click', function(){ closeMenu(); });
      inject.appendChild(a);
    });

    function openMenu(){
      menu.style.display = 'block';
      btn.setAttribute('aria-expanded','true');
    }
    function closeMenu(){
      menu.style.display = 'none';
      btn.setAttribute('aria-expanded','false');
    }

    btn.addEventListener('click', function(){
      const isOpen = (menu.style.display === 'block');
      isOpen ? closeMenu() : openMenu();
    });

    btn.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); btn.click(); }
    });

    document.addEventListener('click', function(e){
      if (!wrap.contains(e.target)) closeMenu();
    });

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') closeMenu();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', build);
  } else {
    build();
  }
})();
</script>

<style>
/* ---------- Student-dashboard-like polish ---------- */
.stat-toggle-wrap{
  display:flex;
  gap:12px;
  align-items:center;
  justify-content:space-between;
  flex-wrap:wrap;
  margin-top:10px;
}
.stat-toggle{
  display:inline-flex;
  border:1px solid rgba(90,130,190,.28);
  background:rgba(255,255,255,.06);
  border-radius:999px;
  padding:4px;
  gap:4px;
}
.stat-toggle button{
  border:0;
  cursor:pointer;
  padding:8px 14px;
  border-radius:999px;
  background:transparent;
  color:rgba(255,255,255,.86);
  font-weight:700;
}
.stat-toggle button.active{
  background:linear-gradient(180deg, rgba(92,156,255,.34), rgba(92,156,255,.18));
  border:1px solid rgba(92,156,255,.35);
}
.stat-kpis{
  display:grid;
  grid-template-columns:repeat(4, minmax(0,1fr));
  gap:12px;
  margin-top:12px;
}
@media (max-width: 980px){
  .stat-kpis{ grid-template-columns:repeat(2, minmax(0,1fr)); }
}
@media (max-width: 520px){
  .stat-kpis{ grid-template-columns:1fr; }
}
.kpi{
  border:1px solid rgba(90,130,190,.22);
  background:rgba(255,255,255,.05);
  border-radius:14px;
  padding:12px 12px 10px;
}
.kpi .k-title{
  color:rgba(255,255,255,.70);
  font-size:12px;
  font-weight:700;
  letter-spacing:.2px;
}
.kpi .k-value{
  margin-top:6px;
  font-size:22px;
  font-weight:900;
  line-height:1.05;
}
.kpi .k-sub{
  margin-top:6px;
  font-size:12px;
  color:rgba(255,255,255,.62);
}
.quick-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:12px;
  margin-top:12px;
}
@media (max-width: 980px){
  .quick-grid{ grid-template-columns:1fr; }
}
.quick{
  border:1px solid rgba(90,130,190,.22);
  background:rgba(255,255,255,.05);
  border-radius:14px;
  padding:12px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}
.quick h4{ margin:0 0 4px 0; }
.quick .muted{ margin:0; }
.quick .actions{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
</style>

<div class="grid">

  <!-- Teacher Stats -->
  <div class="card col-12">
    <h3>My Stats</h3>
    <div class="muted">A quick view of your teaching activity (DB-backed).</div>

    <div class="stat-toggle-wrap">
      <div class="stat-toggle" role="tablist" aria-label="Stats mode">
        <button type="button" id="btnTeaching" class="active" onclick="setMode('teaching')">Teaching</button>
        <button type="button" id="btnReview" onclick="setMode('review')">Review</button>
      </div>
      <div class="muted" id="modeHint">Teaching overview (sections, courses, problems).</div>
    </div>

    <!-- KPIs: Teaching -->
    <div class="stat-kpis" id="kpiTeaching">
      <div class="kpi">
        <div class="k-title">My Sections</div>
        <div class="k-value"><?php echo (int)$sections_count; ?></div>
        <div class="k-sub">Assigned sections you teach.</div>
      </div>
      <div class="kpi">
        <div class="k-title">My Courses</div>
        <div class="k-value"><?php echo (int)$courses_count; ?></div>
        <div class="k-sub">Distinct courses across sections.</div>
      </div>
      <div class="kpi">
        <div class="k-title">Problems Created</div>
        <div class="k-value"><?php echo (int)$problems_count; ?></div>
        <div class="k-sub">Problems under your taught courses.</div>
      </div>
      <div class="kpi">
        <div class="k-title">Create Test</div>
        <div class="k-value">→</div>
        <div class="k-sub"><a class="badge" href="/uiu_brainnext/teacher/teacher_create_test.php">Create Now</a></div>
      </div>
    </div>

    <!-- KPIs: Review -->
    <div class="stat-kpis" id="kpiReview" style="display:none;">
      <div class="kpi">
        <div class="k-title">Total Submissions</div>
        <div class="k-value"><?php echo (int)$total_submissions; ?></div>
        <div class="k-sub">All submissions for your courses.</div>
      </div>
      <div class="kpi">
        <div class="k-title">Last 7 Days</div>
        <div class="k-value"><?php echo (int)$submissions_7d; ?></div>
        <div class="k-sub">New submissions recently.</div>
      </div>
      <div class="kpi">
        <div class="k-title">Manual Checking</div>
        <div class="k-value">→</div>
        <div class="k-sub"><a class="badge" href="/uiu_brainnext/teacher/teacher_check_submissions.php">Open Queue</a></div>
      </div>
      <div class="kpi">
        <div class="k-title">3D Analytics</div>
        <div class="k-value">→</div>
        <div class="k-sub"><a class="badge" href="/uiu_brainnext/teacher/analytics3d.php">Open</a></div>
      </div>
    </div>

    <div class="quick-grid">
      <div class="quick">
        <div>
          <h4>My Sections</h4>
          <p class="muted">View assigned sections and course details.</p>
        </div>
        <div class="actions">
          <a class="badge" href="/uiu_brainnext/teacher/teacher_sections.php">Open</a>
        </div>
      </div>

      <div class="quick">
        <div>
          <h4>Section Performance</h4>
          <p class="muted">Students, attempts, accepted %, average score per section.</p>
        </div>
        <div class="actions">
          <a class="badge" href="/uiu_brainnext/teacher/section_performance.php">Open Dashboard</a>
        </div>
      </div>

      <div class="quick">
        <div>
          <h4>Add Problem</h4>
          <p class="muted">Create practice problems for your courses.</p>
        </div>
        <div class="actions">
          <a class="badge" href="/uiu_brainnext/teacher/teacher_add_problem.php">Add Problem</a>
        </div>
      </div>

      <div class="quick">
        <div>
          <h4>Create / Manage Tests</h4>
          <p class="muted">Create section-based tests and manage them.</p>
        </div>
        <div class="actions">
          <a class="badge" href="/uiu_brainnext/teacher/teacher_create_test.php">Create Test</a>
          <a class="badge" href="/uiu_brainnext/teacher/teacher_tests.php">My Tests</a>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
function setMode(mode){
  const teachBtn = document.getElementById('btnTeaching');
  const revBtn   = document.getElementById('btnReview');
  const hint     = document.getElementById('modeHint');

  const kTeach = document.getElementById('kpiTeaching');
  const kRev   = document.getElementById('kpiReview');

  if(mode === 'review'){
    teachBtn.classList.remove('active');
    revBtn.classList.add('active');
    kTeach.style.display = 'none';
    kRev.style.display = '';
    hint.textContent = 'Review overview (submissions and checking tools).';
  }else{
    revBtn.classList.remove('active');
    teachBtn.classList.add('active');
    kRev.style.display = 'none';
    kTeach.style.display = '';
    hint.textContent = 'Teaching overview (sections, courses, problems).';
  }
}
</script>

<?php ui_end(); ?>





