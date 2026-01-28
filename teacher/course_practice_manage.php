<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$teacher_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($teacher_id <= 0) redirect("/uiu_brainnext/logout.php");

// teacher courses (from sections)
$courses = [];
$st = $conn->prepare("
  SELECT DISTINCT c.id, c.code, c.title
  FROM sections s
  JOIN courses c ON c.id = s.course_id
  WHERE s.teacher_id = ?
  ORDER BY c.code ASC
");
$st->bind_param("i", $teacher_id);
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $courses[] = $row;

$course_id = (int)($_GET["course_id"] ?? 0);
if ($course_id <= 0 && $courses) $course_id = (int)$courses[0]["id"];

$allowed = false;
foreach ($courses as $c) if ((int)$c["id"] === $course_id) { $allowed = true; break; }
if (!$allowed) $course_id = $courses ? (int)$courses[0]["id"] : 0;

// actions: add/remove
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "add" && $course_id > 0) {
  $pid = (int)($_POST["problem_id"] ?? 0);
  if ($pid > 0) {
    $ins = $conn->prepare("INSERT IGNORE INTO course_practice_problems(course_id, problem_id) VALUES (?, ?)");
    $ins->bind_param("ii", $course_id, $pid);
    $ins->execute();
    set_flash("ok", "Added to course practice.");
  }
  redirect("/uiu_brainnext/teacher/course_practice_manage.php?course_id=" . $course_id);
}

if (($_GET["action"] ?? "") === "remove" && $course_id > 0) {
  $pid = (int)($_GET["problem_id"] ?? 0);
  if ($pid > 0) {
    $del = $conn->prepare("DELETE FROM course_practice_problems WHERE course_id=? AND problem_id=? LIMIT 1");
    $del->bind_param("ii", $course_id, $pid);
    $del->execute();
    set_flash("ok", "Removed.");
  }
  redirect("/uiu_brainnext/teacher/course_practice_manage.php?course_id=" . $course_id);
}

$q = trim((string)($_GET["q"] ?? ""));
$ok = get_flash("ok");
$err = get_flash("err");

ui_start("Course Practice Manager", "Teacher Panel");

ui_top_actions([
  ["Dashboard", "/teacher/dashboard.php"],
  ["My Sections", "/teacher/teacher_sections.php"],
  ["Check Submissions", "/teacher/teacher_check_submissions.php"],
]);

echo '<div class="card"><h3>Manage Course Practice Problems</h3>';

if ($ok) echo '<div class="pill pill-ac" style="margin:10px 0;">' . e($ok) . '</div>';
if ($err) echo '<div class="pill pill-bad" style="margin:10px 0;">' . e($err) . '</div>';

if (!$courses) {
  echo '<div class="muted">No courses assigned to you.</div></div>';
  ui_end();
  exit;
}

echo '<form method="GET" class="filters" style="margin-bottom:12px;">';
echo '<div class="fcol-4"><label class="label">Course</label><select name="course_id">';
foreach ($courses as $c) {
  $sel = ((int)$c["id"] === $course_id) ? "selected" : "";
  echo '<option value="' . (int)$c["id"] . "\" $sel>" . e($c["code"] . " — " . $c["title"]) . '</option>';
}
echo '</select></div>';

echo '<div class="fcol-4"><label class="label">Search problems</label><input name="q" value="' . e($q) . '" placeholder="title contains..."></div>';
echo '<div class="fcol-2"><button class="btn-primary" type="submit" style="margin-top:22px;">Open</button></div>';
echo '</form>';

// current set
echo '<h4 style="margin:8px 0;">Current Course Practice Set</h4>';
$stSet = $conn->prepare("
  SELECT p.id, p.title
  FROM course_practice_problems cpp
  JOIN problems p ON p.id = cpp.problem_id
  WHERE cpp.course_id = ?
  ORDER BY cpp.id DESC
");
$stSet->bind_param("i", $course_id);
$stSet->execute();
$rs = $stSet->get_result();

echo '<div class="table-wrap"><table class="table">';
echo '<thead><tr><th>ID</th><th>Title</th><th>Action</th></tr></thead><tbody>';
$hasAny = false;
while ($row = $rs->fetch_assoc()) {
  $hasAny = true;
  $pid = (int)$row["id"];
  $rm = BASE_URL . "/teacher/course_practice_manage.php?course_id=" . $course_id . "&action=remove&problem_id=" . $pid;
  echo "<tr>
    <td><span class='pill pill-block'>{$pid}</span></td>
    <td><span class='pill pill-block'>" . e($row["title"]) . "</span></td>
    <td><a class='badge' href='" . e($rm) . "'>Remove</a></td>
  </tr>";
}
if (!$hasAny) echo "<tr><td colspan='3'><span class='pill pill-wa'>Empty</span></td></tr>";
echo '</tbody></table></div>';

// add by id + search list
echo '<div style="height:14px;"></div>';
echo '<h4 style="margin:8px 0;">Add Problem to Course Practice</h4>';
echo '<form method="POST" class="filters">';
echo '<input type="hidden" name="action" value="add">';
echo '<div class="fcol-4"><label class="label">Problem ID</label><input name="problem_id" type="number" min="1" required></div>';
echo '<div class="fcol-2"><button class="btn-primary" type="submit" style="margin-top:22px;">Add</button></div>';
echo '</form>';

echo '</div>';
ui_end();
