<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";

$TRI = [1 => "Spring", 2 => "Summer", 3 => "Fall"];

$tid = (int)($_SESSION["user"]["id"] ?? 0);

ui_start("Teacher Sections", "My Sections");

// Top actions -> will be injected into NAME dropdown for teacher (via includes/layout.php)
ui_top_actions([
  ["My Sections", "/teacher/teacher_sections.php"],
  ["Section Performance", "/teacher/section_performance.php"],
  ["Check Submissions", "/teacher/teacher_check_submissions.php"],
  ["3D Analytics", "/teacher/analytics3d.php"],
]);

$stmt = $conn->prepare("
  SELECT
    c.code AS course_code,
    c.title AS course_title,
    s.section_label,
    s.trimester,
    s.year
  FROM sections s
  JOIN courses c ON c.id = s.course_id
  WHERE s.teacher_id = ?
  ORDER BY s.year DESC, s.trimester DESC, c.code ASC, s.section_label ASC
");
$stmt->bind_param("i", $tid);
$stmt->execute();
$res = $stmt->get_result();
?>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <h3 style="margin:0 0 6px;">Assigned Sections</h3>
      <div class="muted">Sections assigned to you by admin.</div>
    </div>

  
  </div>

  <div style="margin-top:14px; overflow-x:auto;">
    <table class="table">
      <colgroup>
        <col style="width:44%">
        <col style="width:28%">
        <col style="width:28%">
      </colgroup>
      <thead>
        <tr>
          <th style="text-align:left;">Course</th>
          <th style="text-align:left;">Section</th>
          <th style="text-align:left;">Trimester</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($res->num_rows === 0): ?>
          <tr><td colspan="3" class="muted">No sections assigned yet.</td></tr>
        <?php else: ?>
          <?php while($row = $res->fetch_assoc()): ?>
            <tr>
              <td>
                <div class="pill pill-block">
                  <?= e($row["course_code"] . " - " . $row["course_title"]) ?>
                </div>
              </td>
              <td>
                <div class="pill pill-block">
                  <?= e($row["section_label"]) ?>
                </div>
              </td>
              <td>
                <div class="pill pill-block">
                  <?= e(($TRI[(int)$row["trimester"]] ?? $row["trimester"]) . " / " . $row["year"]) ?>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php ui_end(); ?>

