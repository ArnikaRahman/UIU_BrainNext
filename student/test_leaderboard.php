<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";

$uid = (int)($_SESSION["user"]["id"] ?? 0);
$tid = (int)($_GET["id"] ?? 0);
if ($tid <= 0) redirect("/uiu_brainnext/student/student_tests.php");

/* ---------- DB helpers (MariaDB safe) ---------- */
function db_table_exists(mysqli $conn, string $table): bool {
  $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("s", $table);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}
function db_has_col(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("ss", $table, $col);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}
function pick_first_col(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (db_has_col($conn, $table, $c)) return $c;
  return null;
}

/* ✅ FIX: trimester can be numeric (1/2/3) OR text (Fall/Summer/Spring) */
function trimester_label($v): string {
  $raw = trim((string)$v);
  if ($raw === "") return "";

  // numeric case
  if (ctype_digit($raw)) {
    $n = (int)$raw;
    if ($n === 1) return "Spring";
    if ($n === 2) return "Summer";
    if ($n === 3) return "Fall";
    return "T" . $n;
  }

  // text case
  $x = strtolower($raw);
  if (strpos($x, "spring") !== false) return "Spring";
  if (strpos($x, "summer") !== false) return "Summer";
  if (strpos($x, "fall") !== false || strpos($x, "autumn") !== false) return "Fall";

  return $raw;
}

$need = ["tests","test_submissions","sections","courses","enrollments"];
$missing = [];
foreach ($need as $t) if (!db_table_exists($conn, $t)) $missing[] = $t;

ui_start("Test Leaderboard", "Student Panel");
ui_top_actions([
  ["← Back", "/student/student_tests.php"],
  ["Dashboard", "/student/dashboard.php"],
  ["My Submissions", "/student/submissions.php"],
]);

if ($missing) {
  echo '<div class="card"><h3>Setup Missing</h3>
    <div class="muted">Missing tables: <b>'.e(implode(", ", $missing)).'</b></div>
  </div>';
  ui_end();
  exit;
}

/* ---------- Detect columns ---------- */
$enr_student_col = pick_first_col($conn, "enrollments", ["student_id","user_id"]) ?? "student_id";
$ts_student_col  = pick_first_col($conn, "test_submissions", ["student_id","user_id"]) ?? "student_id";
$ts_status_col   = pick_first_col($conn, "test_submissions", ["status","state"]) ?? "status";
$ts_score_col    = pick_first_col($conn, "test_submissions", ["score","marks"]) ?? "score";
$ts_created_col  = pick_first_col($conn, "test_submissions", ["created_at","submitted_at"]); // tie-breaker

$has_test_title = db_has_col($conn, "tests", "title");
$has_test_desc  = db_has_col($conn, "tests", "description");
$has_total      = db_has_col($conn, "tests", "total_marks");

$TRI = [1=>"Spring", 2=>"Summer", 3=>"Fall"];

/* ---------- Load test info ---------- */
$sql = "SELECT t.id, t.section_id, s.section_label, s.trimester, s.year,
               c.code AS course_code, c.title AS course_title";
if ($has_test_title) $sql .= ", t.title";
if ($has_test_desc)  $sql .= ", t.description";
if ($has_total)      $sql .= ", t.total_marks";
$sql .= "
  FROM tests t
  JOIN sections s ON s.id = t.section_id
  JOIN courses c ON c.id = s.course_id
  WHERE t.id = ?
  LIMIT 1
";
$st = $conn->prepare($sql);
$st->bind_param("i", $tid);
$st->execute();
$test = $st->get_result()->fetch_assoc();

if (!$test) {
  echo '<div class="card"><div class="muted">Test not found.</div></div>';
  ui_end(); exit;
}

/* ---------- Enrollment check (student must belong to section) ---------- */
$chk = $conn->prepare("SELECT 1 FROM enrollments WHERE $enr_student_col=? AND section_id=? LIMIT 1");
$chk->bind_param("ii", $uid, $test["section_id"]);
$chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
  echo '<div class="card"><div class="muted">You are not enrolled in this section.</div></div>';
  ui_end(); exit;
}

/* ---------- Leaderboard data ----------
   Show only Checked results (so teacher-graded only)
*/
$checkedValue = "Checked"; // your system uses "Checked"
$order = "ORDER BY score DESC";
if ($ts_created_col) $order .= ", created_at ASC"; // tie breaker (earlier wins)

$users_exist = db_table_exists($conn, "users");
$user_name_col = $users_exist ? (pick_first_col($conn, "users", ["full_name","name"]) ?? null) : null;

$lbSql = "
  SELECT
    ts.$ts_student_col AS student_id,
    ts.$ts_score_col AS score
";
if ($ts_created_col) $lbSql .= ", ts.$ts_created_col AS created_at";
if ($users_exist) {
  // join users only if exists
  $lbSql .= ", u.username";
  if ($user_name_col) $lbSql .= ", u.$user_name_col AS full_name";
  $lbSql .= "
    FROM test_submissions ts
    LEFT JOIN users u ON u.id = ts.$ts_student_col
  ";
} else {
  $lbSql .= " FROM test_submissions ts ";
}

$lbSql .= "
  WHERE ts.test_id = ?
    AND ts.$ts_status_col = ?
  $order
";

$lb = [];
$stmtLb = $conn->prepare($lbSql);
$stmtLb->bind_param("is", $tid, $checkedValue);
$stmtLb->execute();
$resLb = $stmtLb->get_result();
while ($r = $resLb->fetch_assoc()) $lb[] = $r;

/* ---------- Compute rank in PHP (safe for any MySQL/MariaDB) ---------- */
$ranked = [];
$rank = 0;
$prevScore = null;
$pos = 0;
foreach ($lb as $row) {
  $pos++;
  $sc = $row["score"];
  if ($prevScore === null || $sc != $prevScore) $rank = $pos;
  $prevScore = $sc;
  $row["_rank"] = $rank;
  $ranked[] = $row;
}

/* ---------- Page ---------- */
/* ✅ FIXED: no more (int)"Fall" => 0 */
$tn = trimester_label($test["trimester"] ?? "");

$title = ($has_test_title && !empty($test["title"])) ? $test["title"] : ("Test #".$test["id"]);
$totalText = ($has_total && isset($test["total_marks"])) ? (" / ".$test["total_marks"]) : "";
?>

<div class="card">
  <div class="muted" style="display:flex; gap:10px; flex-wrap:wrap; font-weight:800;">
    <span><?= e($test["course_code"]) ?> - <?= e($test["course_title"]) ?></span>
    <span>•</span>
    <span>Section <?= e($test["section_label"]) ?></span>
    <span>•</span>
    <span><?= e($tn) ?> / <?= e($test["year"]) ?></span>
  </div>

  <div style="height:12px;"></div>
  <h3 style="margin-bottom:6px;"><?= e($title) ?> Leaderboard<?= e($totalText) ?></h3>
  <div class="muted">Only <b>Checked</b> submissions are shown.</div>
</div>

<div style="height:14px;"></div>

<div class="card">
  <?php if (empty($ranked)): ?>
    <div class="muted">No checked results yet. Leaderboard will appear after teacher checks submissions.</div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Rank</th>
          <th>Student</th>
          <th>Name</th>
          <th>Score</th>
          <?php if ($ts_created_col): ?><th>Time</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ranked as $r): ?>
          <?php
            $studentLabel = $r["username"] ?? ("ID: " . (string)$r["student_id"]);
            $nameLabel = $r["full_name"] ?? "-";
          ?>
          <tr>
            <td style="font-weight:900;"><?= (int)$r["_rank"] ?></td>
            <td><?= e($studentLabel) ?></td>
            <td><?= e($nameLabel) ?></td>
            <td style="font-weight:900;"><?= e($r["score"]) ?></td>
            <?php if ($ts_created_col): ?><td><?= e($r["created_at"] ?? "-") ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php ui_end(); ?>

