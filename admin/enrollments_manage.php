<?php
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auth_admin.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $student_username = trim($_POST["student_username"] ?? "");
  $section_id = (int)($_POST["section_id"] ?? 0);

  if ($student_username === "" || $section_id <= 0) {
    set_flash("err", "Student username and section are required.");
    redirect("/uiu_brainnext/admin/enrollments_manage.php");
  }

  $st = $conn->prepare("SELECT id FROM users WHERE username=? AND role='student' LIMIT 1");
  $st->bind_param("s", $student_username);
  $st->execute();
  $student = $st->get_result()->fetch_assoc();

  if (!$student) {
    set_flash("err", "Student not found (username must be student ID).");
    redirect("/uiu_brainnext/admin/enrollments_manage.php");
  }

  $student_id = (int)$student["id"];

  $ins = $conn->prepare("INSERT INTO enrollments(student_id, section_id) VALUES(?,?)");
  $ins->bind_param("ii", $student_id, $section_id);

  if ($ins->execute()) {
    set_flash("ok", "Student enrolled.");
  } else {
    set_flash("err", "Failed. Student may already be enrolled in this section.");
  }

  redirect("/uiu_brainnext/admin/enrollments_manage.php");
}

$err = get_flash("err");
$ok  = get_flash("ok");

$sections = [];
$res = $conn->query("
  SELECT s.id, c.code AS course_code, s.section_label, s.trimester, s.year
  FROM sections s
  JOIN courses c ON c.id = s.course_id
  ORDER BY s.year DESC, s.trimester DESC, c.code ASC, s.section_label ASC
");
while ($r = $res->fetch_assoc()) $sections[] = $r;
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Enroll Students</title>
  <link rel="stylesheet" href="/uiu_brainnext/assets/css/style.css">
</head>
<body>
  <div class="container">
    <div class="nav">
      <div class="left">
        <a class="badge" href="/uiu_brainnext/admin/dashboard.php">← Dashboard</a>
        <div class="muted">Enroll Students</div>
      </div>
      <a class="badge" href="/uiu_brainnext/logout.php">Logout</a>
    </div>

    <?php if ($err): ?><div class="alert err"><?=e($err)?></div><?php endif; ?>
    <?php if ($ok): ?><div class="alert ok"><?=e($ok)?></div><?php endif; ?>

    <div class="card">
      <h3>Enroll a Student into a Section</h3>
      <form method="POST" class="grid">
        <div class="col-6">
          <label class="muted">Student Username (Student ID)</label>
          <input name="student_username" placeholder="011231045" required>
        </div>

        <div class="col-6">
          <label class="muted">Section</label>
          <select name="section_id" required>
            <?php foreach ($sections as $s): ?>
              <option value="<?= (int)$s["id"] ?>">
                <?=e($s["course_code"])?>-<?=e($s["section_label"])?> (T<?=e($s["trimester"])?>/<?=e($s["year"])?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12">
          <button type="submit">Enroll</button>
        </div>
      </form>
    </div>

    <div class="card" style="margin-top:14px;">
      <h3>Tip</h3>
      <p class="muted">
        After enrollment, student should logout and login again to refresh session stats/enrollments.
      </p>
    </div>
  </div>
</body>
</html>
