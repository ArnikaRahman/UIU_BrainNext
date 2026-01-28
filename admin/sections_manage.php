<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

// optional meta logger
$has_meta_logger = file_exists(__DIR__ . "/../includes/meta_logger.php");
if ($has_meta_logger) require_once __DIR__ . "/../includes/meta_logger.php";

if (session_status() === PHP_SESSION_NONE) session_start();

ui_start("Manage Sections", "Admin Panel");
ui_top_actions([
  ["Dashboard", "/admin/dashboard.php"],
  ["Manage Sections", "/admin/sections_manage.php"],
  ["Enroll Students", "/admin/enrollments_manage.php"],
]);

function normalize_trimester(string $t): string {
  $x = trim($t);

  // numeric -> text (handle old wrong data)
  if ($x === "1") return "Spring";
  if ($x === "2") return "Summer";
  if ($x === "3") return "Fall";
  if ($x === "0") return "Fall";

  $lx = strtolower($x);
  if ($lx === "spring") return "Spring";
  if ($lx === "summer") return "Summer";
  if ($lx === "fall")   return "Fall";

  return $x;
}

function clamp_year($y): int {
  $n = (int)$y;
  if ($n < 2000) $n = 2000;
  if ($n > 2100) $n = 2100;
  return $n;
}

/* -------------------- load courses -------------------- */
$courses = [];
$r = $conn->query("SELECT id, code, title FROM courses ORDER BY code ASC");
while ($r && ($row = $r->fetch_assoc())) $courses[] = $row;

/* -------------------- load teachers (ONLY existing) -------------------- */
$teachers = [];
$teacher_ids = []; // for validation
$st = $conn->prepare("SELECT id, username, full_name FROM users WHERE role='teacher' ORDER BY COALESCE(full_name, username) ASC, username ASC");
$st->execute();
$res = $st->get_result();
while ($row = $res->fetch_assoc()) {
  $teachers[] = $row;
  $teacher_ids[(int)$row["id"]] = true;
}

/* -------------------- EDIT mode -------------------- */
$edit_id = (int)($_GET["edit"] ?? 0);
$edit = null;
if ($edit_id > 0) {
  $st = $conn->prepare("SELECT * FROM sections WHERE id=? LIMIT 1");
  $st->bind_param("i", $edit_id);
  $st->execute();
  $edit = $st->get_result()->fetch_assoc();
  if ($edit) {
    $edit["trimester"] = normalize_trimester((string)$edit["trimester"]);
  }
}

$err = get_flash("err");
$ok  = get_flash("ok");

/* -------------------- CREATE / UPDATE -------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = trim($_POST["action"] ?? "");

  $course_id     = (int)($_POST["course_id"] ?? 0);
  $section_label = trim($_POST["section_label"] ?? "");
  $trimester     = normalize_trimester((string)($_POST["trimester"] ?? ""));
  $year          = clamp_year($_POST["year"] ?? date("Y"));

  // teacher assignment: must be existing teacher OR blank (unassigned)
  $teacher_raw = trim($_POST["teacher_id"] ?? "");
  $teacher_id  = null;
  if ($teacher_raw !== "") {
    if (!ctype_digit($teacher_raw)) {
      set_flash("err", "Invalid teacher selected.");
      redirect("/uiu_brainnext/admin/sections_manage.php" . ($action==="update" ? "?edit=".(int)($_POST["id"] ?? 0) : ""));
    }
    $tid = (int)$teacher_raw;
    if (!isset($teacher_ids[$tid])) {
      set_flash("err", "Teacher does not exist (must be an existing teacher account).");
      redirect("/uiu_brainnext/admin/sections_manage.php" . ($action==="update" ? "?edit=".(int)($_POST["id"] ?? 0) : ""));
    }
    $teacher_id = $tid;
  }

  if ($course_id <= 0 || $section_label === "" || $trimester === "" || $year <= 0) {
    set_flash("err", "Please fill Course, Section, Trimester, Year.");
    redirect("/uiu_brainnext/admin/sections_manage.php" . ($action==="update" ? "?edit=".(int)($_POST["id"] ?? 0) : ""));
  }

  if (!in_array($trimester, ["Spring","Summer","Fall"], true)) {
    set_flash("err", "Invalid trimester value.");
    redirect("/uiu_brainnext/admin/sections_manage.php" . ($action==="update" ? "?edit=".(int)($_POST["id"] ?? 0) : ""));
  }

  if ($action === "create") {
    if ($teacher_id === null) {
      $st = $conn->prepare("
        INSERT INTO sections(course_id, section_label, trimester, year, teacher_id)
        VALUES(?,?,?, ?, NULL)
      ");
      $st->bind_param("issi", $course_id, $section_label, $trimester, $year);
    } else {
      $st = $conn->prepare("
        INSERT INTO sections(course_id, section_label, trimester, year, teacher_id)
        VALUES(?,?,?, ?, ?)
      ");
      $st->bind_param("issii", $course_id, $section_label, $trimester, $year, $teacher_id);
    }

    if (!$st || !$st->execute()) {
      set_flash("err", "Insert failed. Maybe duplicate section exists.");
      redirect("/uiu_brainnext/admin/sections_manage.php");
    }

    $new_id = (int)$conn->insert_id;

    if ($has_meta_logger && isset($_SESSION["user"]["id"])) {
      @audit_log((int)$_SESSION["user"]["id"], "admin", "CREATE_SECTION", "sections", $new_id, [
        "course_id" => $course_id,
        "section_label" => $section_label,
        "trimester" => $trimester,
        "year" => $year,
        "teacher_id" => $teacher_id,
      ]);
    }

    set_flash("ok", "Section created successfully.");
    redirect("/uiu_brainnext/admin/sections_manage.php");
  }

  if ($action === "update") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id <= 0) {
      set_flash("err", "Invalid section id.");
      redirect("/uiu_brainnext/admin/sections_manage.php");
    }

    if ($teacher_id === null) {
      $st = $conn->prepare("
        UPDATE sections
        SET course_id=?, section_label=?, trimester=?, year=?, teacher_id=NULL
        WHERE id=?
        LIMIT 1
      ");
      $st->bind_param("issii", $course_id, $section_label, $trimester, $year, $id);
    } else {
      $st = $conn->prepare("
        UPDATE sections
        SET course_id=?, section_label=?, trimester=?, year=?, teacher_id=?
        WHERE id=?
        LIMIT 1
      ");
      $st->bind_param("issiii", $course_id, $section_label, $trimester, $year, $teacher_id, $id);
    }

    if (!$st || !$st->execute()) {
      set_flash("err", "Update failed.");
      redirect("/uiu_brainnext/admin/sections_manage.php?edit=".$id);
    }

    if ($has_meta_logger && isset($_SESSION["user"]["id"])) {
      @audit_log((int)$_SESSION["user"]["id"], "admin", "UPDATE_SECTION", "sections", $id, [
        "course_id" => $course_id,
        "section_label" => $section_label,
        "trimester" => $trimester,
        "year" => $year,
        "teacher_id" => $teacher_id,
      ]);
    }

    set_flash("ok", "Section updated.");
    redirect("/uiu_brainnext/admin/sections_manage.php");
  }
}

/* -------------------- sections list -------------------- */
$sections = [];
$sql = "
  SELECT s.*, c.code AS course_code, c.title AS course_title,
         u.full_name AS teacher_name, u.username AS teacher_user
  FROM sections s
  JOIN courses c ON c.id = s.course_id
  LEFT JOIN users u ON u.id = s.teacher_id
  ORDER BY s.year DESC,
    CASE
      WHEN s.trimester='Spring' THEN 1
      WHEN s.trimester='Summer' THEN 2
      WHEN s.trimester='Fall'   THEN 3
      ELSE 9
    END,
    c.code ASC,
    s.section_label ASC
";
$r = $conn->query($sql);
while ($r && ($row = $r->fetch_assoc())) {
  $row["trimester"] = normalize_trimester((string)$row["trimester"]);
  $sections[] = $row;
}
?>

<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

<div class="grid">

  <div class="card col-6">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
      <div>
        <h3 style="margin:0;"><?= $edit ? "Edit Section" : "Create Section" ?></h3>
        <div class="muted" style="margin-top:6px;">Trimester is stored as <b>Spring/Summer/Fall</b>.</div>
      </div>

      <!-- ✅ Keep Create Teacher button -->
      <a class="badge" href="/uiu_brainnext/admin/teacher_manage.php">+ Create Teacher</a>
    </div>

    <div style="height:12px;"></div>

    <form method="POST">
      <input type="hidden" name="action" value="<?= $edit ? "update" : "create" ?>">
      <?php if ($edit): ?>
        <input type="hidden" name="id" value="<?= (int)$edit["id"] ?>">
      <?php endif; ?>

      <label class="label">Course</label>
      <select name="course_id" required>
        <option value="">Select course</option>
        <?php foreach ($courses as $c): ?>
          <?php $sel = ($edit && (int)$edit["course_id"] === (int)$c["id"]) ? "selected" : ""; ?>
          <option value="<?= (int)$c["id"] ?>" <?= $sel ?>>
            <?= e($c["code"] . " - " . $c["title"]) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div style="height:10px;"></div>

      <label class="label">Section Label</label>
      <input name="section_label" required placeholder="e.g. A"
             value="<?= $edit ? e($edit["section_label"]) : "" ?>">

      <div style="height:10px;"></div>

      <label class="label">Trimester</label>
      <?php $tri_val = $edit ? (string)$edit["trimester"] : ""; ?>
      <select name="trimester" required>
        <option value="">Select trimester</option>
        <option value="Spring" <?= ($tri_val==="Spring")?"selected":"" ?>>Spring</option>
        <option value="Summer" <?= ($tri_val==="Summer")?"selected":"" ?>>Summer</option>
        <option value="Fall"   <?= ($tri_val==="Fall")?"selected":"" ?>>Fall</option>
      </select>

      <div style="height:10px;"></div>

      <label class="label">Year</label>
      <input name="year" type="number" min="2000" max="2100"
             value="<?= $edit ? (int)$edit["year"] : (int)date("Y") ?>" required>

      <div style="height:10px;"></div>

      <label class="label">Teacher (optional)</label>
      <select name="teacher_id">
        <option value="">Unassigned</option>
        <?php foreach ($teachers as $t): ?>
          <?php
            $sel = ($edit && $edit["teacher_id"] !== null && (int)$edit["teacher_id"] === (int)$t["id"]) ? "selected" : "";
            $name = trim((string)($t["full_name"] ?? "")) !== "" ? $t["full_name"] : $t["username"];
          ?>
          <option value="<?= (int)$t["id"] ?>" <?= $sel ?>>
            <?= e($name . " (" . $t["username"] . ")") ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div style="height:14px;"></div>

      <button class="btn-primary" type="submit" style="width:100%;">
        <?= $edit ? "Update Section" : "Create Section" ?>
      </button>

      <?php if ($edit): ?>
        <div style="height:10px;"></div>
        <a class="badge" href="/uiu_brainnext/admin/sections_manage.php">Cancel Edit</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="card col-6">
    <h3>All Sections</h3>
    <div class="muted">Click edit to change course/section/trimester/year/teacher.</div>
    <div style="height:12px;"></div>

    <?php if (empty($sections)): ?>
      <div class="muted">No sections yet.</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Trimester</th>
            <th>Teacher</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($sections as $s): ?>
          <tr>
            <td>
              <div style="font-weight:900;"><?= e($s["course_code"]) ?></div>
              <div class="muted"><?= e($s["course_title"]) ?></div>
            </td>
            <td style="font-weight:900;"><?= e($s["section_label"]) ?></td>
            <td><?= e($s["trimester"]) ?> / <?= (int)$s["year"] ?></td>
            <td>
              <?php if ($s["teacher_id"]): ?>
                <div style="font-weight:900;"><?= e($s["teacher_name"] ?? $s["teacher_user"]) ?></div>
                <div class="muted"><?= e($s["teacher_user"]) ?></div>
              <?php else: ?>
                <span class="muted">Unassigned</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <a class="badge" href="/uiu_brainnext/admin/sections_manage.php?edit=<?= (int)$s["id"] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<?php ui_end(); ?>









