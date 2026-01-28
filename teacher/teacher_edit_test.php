<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$teacher_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($teacher_id <= 0) redirect("/uiu_brainnext/logout.php");

/* =========================
   DB schema helpers
   ========================= */
function db_current_name(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db");
  $row = $r ? $r->fetch_assoc() : null;
  return (string)($row["db"] ?? "");
}
function db_has_table(mysqli $conn, string $table): bool {
  $db = db_current_name($conn);
  if ($db === "") return false;
  $st = $conn->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("ss", $db, $table);
  $st->execute();
  $res = $st->get_result();
  return (bool)$res->fetch_row();
}
function db_has_col(mysqli $conn, string $table, string $col): bool {
  $db = db_current_name($conn);
  if ($db === "") return false;
  $st = $conn->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("sss", $db, $table, $col);
  $st->execute();
  $res = $st->get_result();
  return (bool)$res->fetch_row();
}

function e2($x){ return htmlspecialchars((string)$x, ENT_QUOTES, "UTF-8"); }

/* =========================
   Identify columns (flexible)
   ========================= */
$tests_tbl = "tests";
$sections_tbl = "sections";
$courses_tbl = "courses";
$samples_tbl = "test_samples";

$col_test_id = "id";
$col_created_by = db_has_col($conn,$tests_tbl,"created_by") ? "created_by" : (db_has_col($conn,$tests_tbl,"teacher_id") ? "teacher_id" : "created_by");
$col_section_id = db_has_col($conn,$tests_tbl,"section_id") ? "section_id" : (db_has_col($conn,$tests_tbl,"sec_id") ? "sec_id" : "section_id");

$col_title = db_has_col($conn,$tests_tbl,"title") ? "title" : null;
$col_marks = db_has_col($conn,$tests_tbl,"total_marks") ? "total_marks" : (db_has_col($conn,$tests_tbl,"marks") ? "marks" : null);

$col_question = db_has_col($conn,$tests_tbl,"question_text") ? "question_text" : (db_has_col($conn,$tests_tbl,"question") ? "question" : null);
$col_model = db_has_col($conn,$tests_tbl,"model_answer") ? "model_answer" : null;

$col_due = db_has_col($conn,$tests_tbl,"due_at") ? "due_at" : (db_has_col($conn,$tests_tbl,"due") ? "due" : null);

/* Time window columns */
$col_start = db_has_col($conn,$tests_tbl,"start_at") ? "start_at"
          : (db_has_col($conn,$tests_tbl,"start_time") ? "start_time" : null);
$col_end   = db_has_col($conn,$tests_tbl,"end_at") ? "end_at"
          : (db_has_col($conn,$tests_tbl,"end_time") ? "end_time" : null);

/* Auto-judge ZIP path column (if exists) */
$col_zip = db_has_col($conn,$tests_tbl,"hidden_zip_path") ? "hidden_zip_path"
        : (db_has_col($conn,$tests_tbl,"judge_zip_path") ? "judge_zip_path"
        : (db_has_col($conn,$tests_tbl,"zip_path") ? "zip_path" : null));

/* If you store a flag for auto-judge */
$col_autojudge = db_has_col($conn,$tests_tbl,"is_autojudge") ? "is_autojudge" : null;

/* Sections columns */
$sec_label_col = db_has_col($conn,$sections_tbl,"section_label") ? "section_label"
              : (db_has_col($conn,$sections_tbl,"label") ? "label"
              : (db_has_col($conn,$sections_tbl,"name") ? "name" : null));

$sec_trim_col = db_has_col($conn,$sections_tbl,"trimester") ? "trimester" : null;
$sec_course_col = db_has_col($conn,$sections_tbl,"course_id") ? "course_id" : null;

/* Courses columns */
$course_code_col = db_has_col($conn,$courses_tbl,"code") ? "code" : (db_has_col($conn,$courses_tbl,"course_code") ? "course_code" : null);

/* =========================
   Load test
   ========================= */
$test_id = (int)($_GET["id"] ?? 0);
if ($test_id <= 0) redirect("/uiu_brainnext/teacher/teacher_tests.php");

$sel = "t.$col_test_id AS id";
if ($col_title) $sel .= ", t.$col_title AS title";
if ($col_marks) $sel .= ", t.$col_marks AS total_marks";
if ($col_question) $sel .= ", t.$col_question AS question_text";
if ($col_model) $sel .= ", t.$col_model AS model_answer";
if ($col_due) $sel .= ", t.$col_due AS due_at";
if ($col_start) $sel .= ", t.$col_start AS start_at";
if ($col_end) $sel .= ", t.$col_end AS end_at";
if ($col_zip) $sel .= ", t.$col_zip AS hidden_zip_path";
if ($col_autojudge) $sel .= ", t.$col_autojudge AS is_autojudge";
$sel .= ", t.$col_section_id AS section_id";

$join = "";
$sel2 = "";
if ($sec_course_col && $course_code_col) {
  $join = "JOIN $sections_tbl s ON s.id = t.$col_section_id
           JOIN $courses_tbl c ON c.id = s.$sec_course_col";
  if ($sec_label_col) $sel2 .= ", s.$sec_label_col AS section_label";
  if ($sec_trim_col)  $sel2 .= ", s.$sec_trim_col AS trimester";
  $sel2 .= ", c.$course_code_col AS course_code";
}

$sql = "SELECT $sel $sel2
        FROM $tests_tbl t
        $join
        WHERE t.$col_test_id = ? AND t.$col_created_by = ?
        LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param("ii", $test_id, $teacher_id);
$st->execute();
$test = $st->get_result()->fetch_assoc();
if (!$test) redirect("/uiu_brainnext/teacher/teacher_tests.php");

/* =========================
   Load sections for dropdown (teacher can change)
   ========================= */
$sections = [];
if (db_has_table($conn,$sections_tbl) && $sec_label_col) {
  // Basic list: teacher’s sections if you have created_by column, else all
  $sec_created_col = db_has_col($conn,$sections_tbl,"teacher_id") ? "teacher_id"
                  : (db_has_col($conn,$sections_tbl,"created_by") ? "created_by" : null);

  if ($sec_course_col && $course_code_col) {
    if ($sec_created_col) {
      $sqlSec = "SELECT s.id, s.$sec_label_col AS section_label, s.$sec_trim_col AS trimester, c.$course_code_col AS course_code
                 FROM $sections_tbl s
                 JOIN $courses_tbl c ON c.id = s.$sec_course_col
                 WHERE s.$sec_created_col = ?
                 ORDER BY c.$course_code_col ASC, s.$sec_label_col ASC";
      $stSec = $conn->prepare($sqlSec);
      $stSec->bind_param("i", $teacher_id);
      $stSec->execute();
      $rsSec = $stSec->get_result();
      while ($rsSec && ($row = $rsSec->fetch_assoc())) $sections[] = $row;
    } else {
      $sqlSec = "SELECT s.id, s.$sec_label_col AS section_label, s.$sec_trim_col AS trimester, c.$course_code_col AS course_code
                 FROM $sections_tbl s
                 JOIN $courses_tbl c ON c.id = s.$sec_course_col
                 ORDER BY c.$course_code_col ASC, s.$sec_label_col ASC";
      $rsSec = $conn->query($sqlSec);
      while ($rsSec && ($row = $rsSec->fetch_assoc())) $sections[] = $row;
    }
  } else {
    // fallback without course join
    $rsSec = $conn->query("SELECT id, $sec_label_col AS section_label FROM $sections_tbl ORDER BY id DESC");
    while ($rsSec && ($row = $rsSec->fetch_assoc())) $sections[] = $row;
  }
}

/* =========================
   Load sample cases
   ========================= */
$samples = [];
if (db_has_table($conn, $samples_tbl)) {
  $stS = $conn->prepare("SELECT id, sample_input, sample_output FROM $samples_tbl WHERE test_id = ? ORDER BY id ASC");
  $stS->bind_param("i", $test_id);
  $stS->execute();
  $rsS = $stS->get_result();
  while ($rsS && ($row = $rsS->fetch_assoc())) $samples[] = $row;
}

/* =========================
   POST: Update
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $new_section_id = (int)($_POST["section_id"] ?? (int)$test["section_id"]);
  $title = trim((string)($_POST["title"] ?? ""));
  $question = trim((string)($_POST["question_text"] ?? ""));
  $marks = (int)($_POST["total_marks"] ?? 0);

  $model = trim((string)($_POST["model_answer"] ?? ""));
  $due_at = trim((string)($_POST["due_at"] ?? ""));

  $start_at = trim((string)($_POST["start_at"] ?? ""));
  $end_at = trim((string)($_POST["end_at"] ?? ""));

  $remove_zip = (int)($_POST["remove_zip"] ?? 0);

  // sample rows
  $sample_in = $_POST["sample_in"] ?? [];
  $sample_out = $_POST["sample_out"] ?? [];
  if (!is_array($sample_in)) $sample_in = [];
  if (!is_array($sample_out)) $sample_out = [];

  /* ---- update tests table ---- */
  $set = [];
  $types = "";
  $params = [];

  if ($col_section_id) { $set[] = "$col_section_id = ?"; $types .= "i"; $params[] = $new_section_id; }
  if ($col_title) { $set[] = "$col_title = ?"; $types .= "s"; $params[] = $title; }
  if ($col_question) { $set[] = "$col_question = ?"; $types .= "s"; $params[] = $question; }
  if ($col_marks) { $set[] = "$col_marks = ?"; $types .= "i"; $params[] = $marks; }

  // model answer
  if ($col_model) { $set[] = "$col_model = ?"; $types .= "s"; $params[] = $model; }

  // due
  if ($col_due) { $set[] = "$col_due = ?"; $types .= "s"; $params[] = $due_at; }

  // time window
  if ($col_start) { $set[] = "$col_start = ?"; $types .= "s"; $params[] = $start_at; }
  if ($col_end) { $set[] = "$col_end = ?"; $types .= "s"; $params[] = $end_at; }

  // ZIP remove request
  $zip_path_now = (string)($test["hidden_zip_path"] ?? "");
  if ($remove_zip === 1 && $col_zip) {
    $set[] = "$col_zip = ''";
    // also set is_autojudge off if exists
    if ($col_autojudge) { $set[] = "$col_autojudge = 0"; }
    // delete file from disk if exists
    if ($zip_path_now !== "") {
      $abs = $_SERVER["DOCUMENT_ROOT"] . $zip_path_now;
      if (is_file($abs)) @unlink($abs);
    }
  }

  // ZIP upload (replace)
  if (isset($_FILES["hidden_zip"]) && is_array($_FILES["hidden_zip"]) && ($_FILES["hidden_zip"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (($_FILES["hidden_zip"]["error"] ?? 0) === UPLOAD_ERR_OK) {
      $name = (string)($_FILES["hidden_zip"]["name"] ?? "");
      $tmp = (string)($_FILES["hidden_zip"]["tmp_name"] ?? "");
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

      if ($ext !== "zip") {
        set_flash("err", "Please upload a valid .zip file.");
      } else {
        // store in /uiu_brainnext/uploads/tests/test_{id}/judge.zip
        $baseRel = "/uiu_brainnext/uploads/tests/test_" . $test_id;
        $baseAbs = rtrim($_SERVER["DOCUMENT_ROOT"], "/\\") . $baseRel;

        if (!is_dir($baseAbs)) @mkdir($baseAbs, 0777, true);

        $destAbs = $baseAbs . "/judge.zip";
        $destRel = $baseRel . "/judge.zip";

        // remove old
        if ($zip_path_now !== "") {
          $oldAbs = $_SERVER["DOCUMENT_ROOT"] . $zip_path_now;
          if (is_file($oldAbs)) @unlink($oldAbs);
        }

        if (@move_uploaded_file($tmp, $destAbs)) {
          if ($col_zip) {
            $set[] = "$col_zip = ?";
            $types .= "s";
            $params[] = $destRel;
          }
          if ($col_autojudge) {
            $set[] = "$col_autojudge = 1";
          }
          set_flash("ok", "Hidden judge ZIP uploaded successfully.");
        } else {
          set_flash("err", "Failed to upload ZIP. Check folder permissions.");
        }
      }
    } else {
      set_flash("err", "Upload error.");
    }
  }

  if (!empty($set)) {
    $sqlU = "UPDATE $tests_tbl SET " . implode(", ", $set) . " WHERE $col_test_id = ? AND $col_created_by = ? LIMIT 1";
    $stU = $conn->prepare($sqlU);

    $types2 = $types . "ii";
    $params[] = $test_id;
    $params[] = $teacher_id;

    $bind = [];
    $bind[] = $types2;
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array([$stU, "bind_param"], $bind);

    $stU->execute();
  }

  /* ---- sample cases: replace all ---- */
  if (db_has_table($conn, $samples_tbl)) {
    $stD = $conn->prepare("DELETE FROM $samples_tbl WHERE test_id = ?");
    $stD->bind_param("i", $test_id);
    $stD->execute();

    $stI = $conn->prepare("INSERT INTO $samples_tbl (test_id, sample_input, sample_output) VALUES (?, ?, ?)");
    for ($i = 0; $i < max(count($sample_in), count($sample_out)); $i++) {
      $si = trim((string)($sample_in[$i] ?? ""));
      $so = trim((string)($sample_out[$i] ?? ""));
      if ($si === "" && $so === "") continue;
      $stI->bind_param("iss", $test_id, $si, $so);
      $stI->execute();
    }
  }

  set_flash("ok", "Test updated.");
  redirect("/uiu_brainnext/teacher/teacher_test_view.php?id=" . $test_id);
}

/* =========================
   UI
   ========================= */
ui_start("Edit Test", "Teacher Panel");

$TRI = [1=>"Spring",2=>"Summer",3=>"Fall"];
$zip_now = (string)($test["hidden_zip_path"] ?? "");
$is_autojudge = (int)($test["is_autojudge"] ?? 0);
?>
<style>
/* layout */
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:900px){.grid2{grid-template-columns:1fr;}}

.samples-grid{
  display:grid;
  grid-template-columns: 1fr 1fr 140px;
  gap:12px;
  align-items:stretch;
}
@media(max-width:900px){
  .samples-grid{ grid-template-columns:1fr; }
}
.samples-grid textarea{ width:100%; min-height:110px; }
.rmwrap{
  display:flex;
  align-items:center;
  justify-content:center;
}
.rmBtn{
  width:100%;
  display:inline-flex;
  justify-content:center;
  align-items:center;
  padding:10px 14px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.16);
  background:rgba(255,255,255,.06);
  color:inherit;
  cursor:pointer;
  font-weight:900;
}
.rmBtn:hover{ background:rgba(255,255,255,.10); }
</style>

<div class="card">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
    <div>
      <h3 style="margin:0;">Edit Test</h3>
      <div class="muted">
        <?= e2((string)($test["course_code"] ?? "")) ?>
        <?= ($test["section_label"] ?? "")!=="" ? ("-".e2((string)$test["section_label"])) : "" ?>
        <?php if (!empty($test["trimester"])): ?>
          (<?= e2($TRI[(int)$test["trimester"]] ?? ("T".(int)$test["trimester"])) ?>/<?= date("Y") ?>)
        <?php endif; ?>
      </div>
    </div>
    <a class="badge" href="/uiu_brainnext/teacher/teacher_test_view.php?id=<?= (int)$test_id ?>">← Back</a>
  </div>

  <div style="height:14px;"></div>

  <form method="POST" enctype="multipart/form-data">

    <!-- Section -->
    <?php if (!empty($sections)): ?>
      <label class="label">Section</label>
      <select name="section_id">
        <?php foreach ($sections as $s): ?>
          <?php
            $sid = (int)($s["id"] ?? 0);
            $label = (string)($s["section_label"] ?? "");
            $code  = (string)($s["course_code"] ?? "");
            $tri   = (int)($s["trimester"] ?? 0);
            $show  = trim($code . " - " . $label);
            if ($tri > 0) $show .= " (" . ($TRI[$tri] ?? ("T".$tri)) . "/" . date("Y") . ")";
          ?>
          <option value="<?= $sid ?>" <?= ((int)$test["section_id"]===$sid)?"selected":"" ?>>
            <?= e2($show) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div style="height:12px;"></div>
    <?php endif; ?>

    <!-- Title -->
    <label class="label">Test Title</label>
    <input name="title" value="<?= e2((string)($test["title"] ?? "")) ?>" required>

    <div style="height:12px;"></div>

    <!-- Question -->
    <label class="label">Test Question</label>
    <textarea name="question_text" rows="7" required><?= e2((string)($test["question_text"] ?? "")) ?></textarea>

    <div style="height:12px;"></div>

    <!-- Marks + Due -->
    <div class="grid2">
      <div>
        <label class="label">Total Marks</label>
        <input type="number" min="0" name="total_marks" value="<?= (int)($test["total_marks"] ?? 0) ?>">
      </div>
      <div>
        <label class="label">Due (optional)</label>
        <input name="due_at" value="<?= e2((string)($test["due_at"] ?? "")) ?>" placeholder="YYYY-MM-DD HH:MM:SS">
      </div>
    </div>

    <div style="height:12px;"></div>

    <!-- Time window -->
    <div class="card" style="padding:14px;">
      <div style="font-weight:900;margin-bottom:6px;">Test Time Window</div>
      <div class="muted" style="margin-bottom:10px;">Students can submit only within this start/end time.</div>

      <div class="grid2">
        <div>
          <label class="label">Start Time</label>
          <input type="datetime-local" name="start_at" value="<?= e2((string)($test["start_at"] ?? "")) ?>">
        </div>
        <div>
          <label class="label">End Time</label>
          <input type="datetime-local" name="end_at" value="<?= e2((string)($test["end_at"] ?? "")) ?>">
        </div>
      </div>
      <div class="muted smallmono" style="margin-top:8px;">If empty, test is always open.</div>
    </div>

    <div style="height:12px;"></div>

    <!-- Auto judge ZIP -->
    <div class="card" style="padding:14px;">
      <div style="font-weight:900;margin-bottom:6px;">Hidden Testcases ZIP (Auto Judge)</div>
      <div class="muted" style="margin-bottom:10px;">
        Optional. Upload a ZIP to make this test auto-judgeable (C/C++).
        Supported formats: <b>inputs/</b> + <b>outputs/</b> OR <b>1.in + 1.out</b>.
      </div>

      <?php if ($zip_now !== ""): ?>
        <div class="muted" style="margin-bottom:10px;">
          ✅ ZIP already uploaded:
          <span class="smallmono"><?= e2($zip_now) ?></span>
        </div>
        <label class="muted" style="display:flex;gap:8px;align-items:center;">
          <input type="checkbox" name="remove_zip" value="1">
          Remove existing ZIP (disable auto-judge)
        </label>
        <div style="height:10px;"></div>
      <?php endif; ?>

      <label class="label">Upload / Replace ZIP</label>
      <input type="file" name="hidden_zip" accept=".zip">
    </div>

    <div style="height:12px;"></div>

    <!-- Model answer (manual tests) -->
    <div class="card" style="padding:14px;">
      <div style="font-weight:900;margin-bottom:6px;">Model Answer</div>
      <div class="muted" style="margin-bottom:10px;">
        Used for manual-check tests. For auto-judge tests, you can keep it empty.
      </div>
      <input name="model_answer" value="<?= e2((string)($test["model_answer"] ?? "")) ?>" placeholder="Optional model answer">
    </div>

    <div style="height:12px;"></div>

    <!-- Sample cases -->
    <div class="card" style="padding:14px;">
      <div style="font-weight:900;margin-bottom:6px;">Sample Cases</div>
      <div class="muted" style="margin-bottom:10px;">These are visible to students.</div>

      <div id="samplesWrap">
        <?php if (empty($samples)): ?>
          <div class="samples-grid sample-row">
            <textarea name="sample_in[]" placeholder="Sample input"></textarea>
            <textarea name="sample_out[]" placeholder="Sample output"></textarea>
            <div class="rmwrap"><button type="button" class="rmBtn">Remove</button></div>
          </div>
        <?php else: ?>
          <?php foreach ($samples as $s): ?>
            <div class="samples-grid sample-row">
              <textarea name="sample_in[]" placeholder="Sample input"><?= e2((string)$s["sample_input"]) ?></textarea>
              <textarea name="sample_out[]" placeholder="Sample output"><?= e2((string)$s["sample_output"]) ?></textarea>
              <div class="rmwrap"><button type="button" class="rmBtn">Remove</button></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div style="height:10px;"></div>
      <button type="button" class="badge" id="addRowBtn">+ Add Row</button>
    </div>

    <div style="height:14px;"></div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn-primary" type="submit">Save Changes</button>
      <a class="badge" href="/uiu_brainnext/teacher/teacher_test_view.php?id=<?= (int)$test_id ?>">Cancel</a>
    </div>
  </form>
</div>

<script>
(function(){
  const wrap = document.getElementById("samplesWrap");
  const addBtn = document.getElementById("addRowBtn");

  function bindRemove(btn){
    btn.addEventListener("click", function(){
      const row = btn.closest(".sample-row");
      if (row) row.remove();
    });
  }

  document.querySelectorAll(".rmBtn").forEach(bindRemove);

  addBtn.addEventListener("click", function(){
    const div = document.createElement("div");
    div.className = "samples-grid sample-row";
    div.innerHTML = `
      <textarea name="sample_in[]" placeholder="Sample input"></textarea>
      <textarea name="sample_out[]" placeholder="Sample output"></textarea>
      <div class="rmwrap"><button type="button" class="rmBtn">Remove</button></div>
    `;
    wrap.appendChild(div);
    bindRemove(div.querySelector(".rmBtn"));
  });
})();
</script>

<?php ui_end(); ?>

