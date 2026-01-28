<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/meta_logger.php";
require_once __DIR__ . "/../includes/testcase_zip.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* ✅ Fix timezone mismatch */
date_default_timezone_set("Asia/Dhaka");

$teacher_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($teacher_id <= 0) redirect("/uiu_brainnext/logout.php");

/* ---------------- DB helpers ---------------- */
function db_current_name(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db");
  $row = $r ? $r->fetch_assoc() : null;
  return (string)($row["db"] ?? "");
}
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
function db_table_exists(mysqli $conn, string $table): bool {
  $sql = "SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("s", $table);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}
function first_existing_col(mysqli $conn, string $table, array $cands): ?string {
  foreach ($cands as $c) if (db_has_col($conn, $table, $c)) return $c;
  return null;
}
function parse_dt_local_to_sql(?string $v): string {
  $v = trim((string)$v);
  if ($v === "") return "";
  $v = str_replace("T", " ", $v);
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) $v .= ":00";
  return $v;
}

$missing = [];
foreach (["tests","sections","test_samples"] as $t) if (!db_table_exists($conn, $t)) $missing[] = $t;

/* ---- tests table columns ---- */
$has_title   = db_has_col($conn, "tests", "title");
$has_desc    = db_has_col($conn, "tests", "description");
$has_total   = db_has_col($conn, "tests", "total_marks");
$has_qtext   = db_has_col($conn, "tests", "question_text");
$has_created = db_has_col($conn, "tests", "created_by");

/* time window columns (support multiple schema names) */
$test_start_col = first_existing_col($conn, "tests", ["start_time","starts_at","start_at","start_datetime","open_time"]);
$test_end_col   = first_existing_col($conn, "tests", ["end_time","ends_at","end_at","end_datetime","close_time"]);
$has_time_window = ($test_start_col !== null || $test_end_col !== null);

/* ---- sections table columns ---- */
$sec_label_col   = first_existing_col($conn, "sections", ["section_label","name","section_name","section","label"]);
$sec_trim_col    = first_existing_col($conn, "sections", ["trimester","semester","term"]);
$sec_year_col    = first_existing_col($conn, "sections", ["year","session_year"]);
$sec_teacher_col = first_existing_col($conn, "sections", ["teacher_id","instructor_id","user_id","created_by"]);
$sec_course_col  = first_existing_col($conn, "sections", ["course_id","courseID"]);

$course_code_col  = first_existing_col($conn, "courses", ["code","course_code"]);
$course_title_col = first_existing_col($conn, "courses", ["title","course_title","name"]);

/* kept (may be used elsewhere) */
$TRI = [1=>"Spring",2=>"Summer",3=>"Fall"];

/* ---------------- teacher sections ---------------- */
$sections = [];
if (empty($missing)) {
  $sec_label_col   = $sec_label_col   ?: "id";
  $sec_teacher_col = $sec_teacher_col ?: "teacher_id";
  $sec_course_col  = $sec_course_col  ?: "course_id";
  $course_code_col  = $course_code_col  ?: "code";
  $course_title_col = $course_title_col ?: "title";

  $selTrim = $sec_trim_col ? "s.`$sec_trim_col` AS trimester" : "NULL AS trimester";
  $selYear = $sec_year_col ? "s.`$sec_year_col` AS year" : "NULL AS year";
  $selCode = db_has_col($conn, "courses", $course_code_col) ? "c.`$course_code_col` AS code" : "'' AS code";
  $selCttl = db_has_col($conn, "courses", $course_title_col) ? "c.`$course_title_col` AS title" : "'' AS title";

  $ordYear = $sec_year_col ? "s.`$sec_year_col` DESC," : "";
  $ordTrim = $sec_trim_col ? "s.`$sec_trim_col` DESC," : "";

  $sql = "
    SELECT
      s.id,
      s.`$sec_label_col` AS section_label,
      $selTrim,
      $selYear,
      $selCode,
      $selCttl
    FROM sections s
    JOIN courses c ON c.id = s.`$sec_course_col`
    WHERE s.`$sec_teacher_col` = ?
    ORDER BY $ordYear $ordTrim c.id DESC, section_label ASC
  ";
  $st = $conn->prepare($sql);
  if ($st) {
    $st->bind_param("i", $teacher_id);
    $st->execute();
    $r = $st->get_result();
    while ($r && ($row = $r->fetch_assoc())) $sections[] = $row;
  }
}

/* ---------------- POST create test ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $section_id = (int)($_POST["section_id"] ?? 0);
  $title      = trim((string)($_POST["title"] ?? ""));
  $desc       = trim((string)($_POST["description"] ?? ""));
  $question   = trim((string)($_POST["question_text"] ?? ""));
  $total      = (int)($_POST["total_marks"] ?? 10);

  $start_sql = parse_dt_local_to_sql($_POST["start_time"] ?? "");
  $end_sql   = parse_dt_local_to_sql($_POST["end_time"] ?? "");

  if ($start_sql !== "" && $end_sql !== "" && strtotime($start_sql) && strtotime($end_sql)) {
    if (strtotime($start_sql) > strtotime($end_sql)) {
      set_flash("err", "Start time must be before End time.");
      redirect("/uiu_brainnext/teacher/teacher_create_test.php");
    }
  }

  $sample_in  = $_POST["sample_input"] ?? [];
  $sample_out = $_POST["sample_output"] ?? [];
  $samples = [];
  for ($i=0; $i<count($sample_in); $i++) {
    $in  = trim((string)($sample_in[$i] ?? ""));
    $out = trim((string)($sample_out[$i] ?? ""));
    if ($in === "" && $out === "") continue;
    $samples[] = ["in"=>$in, "out"=>$out];
  }

  if ($section_id <= 0) { set_flash("err","Section is required."); redirect("/uiu_brainnext/teacher/teacher_create_test.php"); }
  if ($has_title && $title === "") { set_flash("err","Title is required."); redirect("/uiu_brainnext/teacher/teacher_create_test.php"); }
  if ($has_qtext && $question === "") { set_flash("err","Question text is required."); redirect("/uiu_brainnext/teacher/teacher_create_test.php"); }
  if (count($samples) === 0) { set_flash("err","Add at least 1 sample input/output row."); redirect("/uiu_brainnext/teacher/teacher_create_test.php"); }

  /* insert test */
  $cols = ["section_id"];
  $vals = [$section_id];

  if ($has_title)   { $cols[]="title"; $vals[]=$title; }
  if ($has_desc)    { $cols[]="description"; $vals[]=$desc; }
  if ($has_qtext)   { $cols[]="question_text"; $vals[]=$question; }
  if ($has_total)   { $cols[]="total_marks"; $vals[]=$total; }
  if ($has_created) { $cols[]="created_by"; $vals[]=$teacher_id; }

  if ($test_start_col && $start_sql !== "") { $cols[] = $test_start_col; $vals[] = $start_sql; }
  if ($test_end_col   && $end_sql   !== "") { $cols[] = $test_end_col;   $vals[] = $end_sql; }

  $placeholders = implode(",", array_fill(0, count($cols), "?"));
  $colSql = implode(",", array_map(fn($c)=>"`$c`", $cols));

  $sqlIns = "INSERT INTO tests ($colSql) VALUES ($placeholders)";
  $ins = $conn->prepare($sqlIns);
  if (!$ins) {
    set_flash("err","Prepare failed: ".$conn->error);
    redirect("/uiu_brainnext/teacher/teacher_create_test.php");
  }

  $types = "";
  foreach ($vals as $v) $types .= is_int($v) ? "i" : "s";
  $ins->bind_param($types, ...$vals);

  if (!$ins->execute()) {
    set_flash("err","Insert failed: ".$conn->error);
    redirect("/uiu_brainnext/teacher/teacher_create_test.php");
  }

  $test_id = (int)$conn->insert_id;

  /* insert test samples */
  $insS = $conn->prepare("INSERT INTO test_samples(test_id, sample_input, sample_output, sort_order) VALUES(?, ?, ?, ?)");
  $order = 1;
  foreach ($samples as $s) {
    $in = $s["in"]; $out = $s["out"];
    $insS->bind_param("issi", $test_id, $in, $out, $order);
    $insS->execute();
    $order++;
  }

  // AUDIT LOG
  audit_log($teacher_id, "teacher", "CREATE_TEST", "tests", $test_id, [
    "section_id" => $section_id,
    "title" => ($title !== "" ? $title : ("Test #".$test_id)),
    "total_marks" => $has_total ? $total : null,
    "sample_count" => count($samples),
    "time_window" => ["start"=>$start_sql ?: null, "end"=>$end_sql ?: null]
  ]);

  // ============================
  // Hidden testcase ZIP -> extract
  // ============================
  $zip_ok = false;
  $zip_msg = "";

  if (isset($_FILES["hidden_zip"]) && is_array($_FILES["hidden_zip"])) {
    $f = $_FILES["hidden_zip"];
    if (($f["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $baseDir = __DIR__ . "/../judge/testcases";
      $destDir = $baseDir . "/test_" . (int)$test_id;
      ensure_dir($baseDir);

      // IMPORTANT: use the strict alias (your project used this name in error)
      [$zip_ok, $zip_msg] = extract_testcase_zip_strict((string)($f["tmp_name"] ?? ""), $destDir);
    }
  }

if ($zip_ok) {
  set_flash(
    "ok",
    "Test created. Hidden testcases extracted ✅ (Auto-judge enabled)."
  );
} else if ($zip_msg !== "") {
  set_flash(
    "ok",
    "Test created, but hidden testcases were not applied."
  );
} else {
  set_flash(
    "ok",
    "Test created with ".count($samples)." sample case(s)."
  );
}


  redirect("/uiu_brainnext/teacher/teacher_create_test.php");
}

/* ---------------- UI ---------------- */
ui_start("Create Test", "Teacher Panel");
ui_top_actions([
  ["Dashboard", "/teacher/dashboard.php"],
  ["My Sections", "/teacher/teacher_sections.php"],
  ["My Tests", "/teacher/teacher_tests.php"],
]);

$err = get_flash("err");
$ok  = get_flash("ok");
if ($err) echo '<div class="alert err">'.e($err).'</div>';
if ($ok)  echo '<div class="alert ok">'.e($ok).'</div>';

if (!empty($missing)) {
  echo '<div class="card"><h3>Setup Missing</h3>
    <div class="muted">Missing tables: <b>'.e(implode(", ", $missing)).'</b></div>
  </div>';
  ui_end(); exit;
}
?>

<style>
#samplesTable { width:100%; table-layout: fixed; }
#samplesTable th, #samplesTable td { vertical-align: top; }
#samplesTable th:last-child,
#samplesTable td:last-child { width:140px; min-width:140px; text-align:right; white-space:nowrap; }
#samplesTable textarea { width:100%; box-sizing:border-box; min-height:110px; }
</style>

<div class="card">
  <h3>Create Test</h3>
  <p class="muted">Create a test for a section with multiple sample input/output rows.</p>

  <form method="POST" enctype="multipart/form-data">
    <label class="label">Section</label>
    <select name="section_id" required>
      <option value="">Select section</option>
      <?php foreach ($sections as $s): ?>
        <?php
          // ✅ FIX: trimester can be stored as "Fall"/"Spring"/"Summer" (string) OR 1/2/3 (int)
          $triRaw = $s["trimester"] ?? "";
          $triRawStr = trim((string)$triRaw);

          if ($triRawStr === "") {
            $tn = "T?";
          } elseif (ctype_digit($triRawStr)) {
            $triInt = (int)$triRawStr;
            $tn = $TRI[$triInt] ?? ("T".$triInt);
          } else {
            // normalize string values
            $low = strtolower($triRawStr);
            if ($low === "spring") $tn = "Spring";
            elseif ($low === "summer") $tn = "Summer";
            elseif ($low === "fall") $tn = "Fall";
            else $tn = $triRawStr; // keep whatever it is
          }

          $yr = (string)($s["year"] ?? "");
        ?>
        <option value="<?= (int)$s["id"] ?>">
          <?= e((string)($s["code"] ?? "")) ?> - <?= e((string)($s["section_label"] ?? "")) ?>
          <?php if ($yr !== ""): ?> (<?= e($tn) ?>/<?= e($yr) ?>)<?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div style="height:12px;"></div>

    <?php if ($has_title): ?>
      <label class="label">Test Title</label>
      <input name="title" placeholder="Example: Quiz 1" required>
      <div style="height:12px;"></div>
    <?php endif; ?>

    <?php if ($has_desc): ?>
      <label class="label">Instructions (optional)</label>
      <textarea name="description" placeholder="Any rules/instructions..."></textarea>
      <div style="height:12px;"></div>
    <?php endif; ?>

    <?php if ($has_qtext): ?>
      <label class="label">Test Question</label>
      <textarea name="question_text" placeholder="Write the test question..." required></textarea>
      <div style="height:12px;"></div>
    <?php endif; ?>

    <?php if ($has_total): ?>
      <label class="label">Total Marks</label>
      <input type="number" name="total_marks" value="10" min="0">
      <div style="height:12px;"></div>
    <?php endif; ?>

    <div class="card" style="margin-top:10px;">
      <h3 style="margin-bottom:6px;">Test Time Window</h3>
      <div class="muted">Students can submit only within this start/end time.</div>
      <div style="height:10px;"></div>

      <?php if ($has_time_window): ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label class="label">Start Time</label>
            <input type="datetime-local" name="start_time">
          </div>
          <div>
            <label class="label">End Time</label>
            <input type="datetime-local" name="end_time">
          </div>
        </div>
      <?php else: ?>
        <div class="muted">
          Your <b>tests</b> table has no start/end time columns.
          Add columns like <span class="smallmono">start_time</span> and <span class="smallmono">end_time</span>.
        </div>
      <?php endif; ?>
    </div>

    <div style="height:12px;"></div>

    <label class="label">Hidden Testcases ZIP (Auto Judge)</label>
    <input type="file" name="hidden_zip" accept=".zip">
    <div class="muted" style="margin-top:6px;">
      ZIP must contain <span class="smallmono">inputs/</span> & <span class="smallmono">outputs/</span>
      (same basenames) OR <span class="smallmono">1.in, 1.out</span> style.
    </div>

    <div style="height:12px;"></div>

    <div class="card">
      <h3 style="margin-bottom:6px;">Sample Cases</h3>
      <div class="muted">Add multiple sample input/output pairs (table rows).</div>

      <div style="height:10px;"></div>

      <table class="table" id="samplesTable">
        <thead>
          <tr>
            <th style="width:50%;">Sample Input</th>
            <th style="width:50%;">Sample Output</th>
            <th> </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><textarea name="sample_input[]" required></textarea></td>
            <td><textarea name="sample_output[]" required></textarea></td>
            <td><button type="button" class="badge" onclick="removeRow(this)">Remove</button></td>
          </tr>
        </tbody>
      </table>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
        <button type="button" class="badge" onclick="addRow()">+ Add Row</button>
      </div>
    </div>

    <div style="height:12px;"></div>
    <button class="btn-primary" type="submit">Create Test</button>
  </form>
</div>

<script>
function addRow(){
  const tb = document.querySelector("#samplesTable tbody");
  const tr = document.createElement("tr");
  tr.innerHTML = `
    <td><textarea name="sample_input[]" required></textarea></td>
    <td><textarea name="sample_output[]" required></textarea></td>
    <td><button type="button" class="badge" onclick="removeRow(this)">Remove</button></td>`;
  tb.appendChild(tr);
}
function removeRow(btn){
  const tb = document.querySelector("#samplesTable tbody");
  if(tb.children.length <= 1) return;
  btn.closest("tr").remove();
}
</script>

<?php ui_end(); ?>










