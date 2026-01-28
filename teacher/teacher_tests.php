<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set("Asia/Dhaka");

$teacher_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($teacher_id <= 0) redirect("/uiu_brainnext/logout.php");

function db_has_col(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("ss", $table, $col);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}
function pick_col(mysqli $conn, string $table, array $cands): ?string {
  foreach ($cands as $c) if (db_has_col($conn, $table, $c)) return $c;
  return null;
}
function fmt_dt(?string $v): string {
  $v = trim((string)$v);
  if ($v === "" || $v === "0000-00-00 00:00:00") return "-";
  $ts = strtotime($v);
  return $ts ? date("d M Y, h:i A", $ts) : $v;
}

$TRI = [1=>"Spring",2=>"Summer",3=>"Fall"];

/* detect columns */
$due_col   = pick_col($conn, "tests", ["due_at","due","due_date","due_datetime"]);
$end_col   = pick_col($conn, "tests", ["end_time","ends_at","end_at","end_datetime","close_time"]);
$title_col = pick_col($conn, "tests", ["title","test_title","name"]) ?: "title";
$total_col = pick_col($conn, "tests", ["total_marks","marks","total"]) ?: "total_marks";

$sel_due = $due_col ? "t.`$due_col` AS due_dt" : ($end_col ? "t.`$end_col` AS due_dt" : "NULL AS due_dt");

$sql = "
  SELECT
    t.id,
    t.`$title_col` AS title,
    t.`$total_col` AS total_marks,
    $sel_due,
    c.code AS course_code,
    s.section_label,
    s.trimester,
    s.year
  FROM tests t
  JOIN sections s ON s.id = t.section_id
  JOIN courses c ON c.id = s.course_id
  WHERE t.created_by = ?
  ORDER BY t.id DESC
";
$st = $conn->prepare($sql);
$st->bind_param("i", $teacher_id);
$st->execute();
$res = $st->get_result();
$tests = [];
while ($row = $res->fetch_assoc()) $tests[] = $row;

ui_start("My Tests", "Teacher Panel");
ui_top_actions([
  ["Dashboard", "/teacher/dashboard.php"],
  ["Create Test", "/teacher/teacher_create_test.php"],
]);
?>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <h3 style="margin:0;">Created Tests</h3>
    <a class="badge" href="/uiu_brainnext/teacher/teacher_create_test.php">+ Create Test</a>
  </div>

  <div style="height:12px;"></div>

  <?php if (empty($tests)): ?>
    <div class="muted">No tests created yet.</div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Test</th>
          <th>Section</th>
          <th>Marks</th>
          <th>Due</th>
          <th style="width:160px;"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tests as $t): ?>
        <?php
          $tri = (int)($t["trimester"] ?? 0);
          $tn  = $TRI[$tri] ?? ($tri ? ("T".$tri) : "");
          $yr  = (string)($t["year"] ?? "");
          $due = fmt_dt($t["due_dt"] ?? "");
        ?>
        <tr>
          <td><?= e((string)$t["title"]) ?></td>
          <td>
            <?= e((string)$t["course_code"]) ?>-<?= e((string)$t["section_label"]) ?>
            <?= $yr !== "" ? "(".e($tn)."/".e($yr).")" : "" ?>
          </td>
          <td><?= (int)($t["total_marks"] ?? 0) ?></td>
          <td><?= e($due) ?></td>
          <td style="text-align:right;white-space:nowrap;">
            <a class="badge" href="/uiu_brainnext/teacher/teacher_test_view.php?id=<?= (int)$t["id"] ?>">Open</a>
            <a class="badge" href="/uiu_brainnext/teacher/teacher_edit_test.php?id=<?= (int)$t["id"] ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php ui_end(); ?>




