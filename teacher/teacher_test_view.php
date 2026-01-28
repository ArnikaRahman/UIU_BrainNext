<?php
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/test_cases_files.php";

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set("Asia/Dhaka");

$tid = (int)($_SESSION["user"]["id"] ?? 0);
if ($tid <= 0) redirect("/uiu_brainnext/logout.php");

$TRI = [1=>"Spring",2=>"Summer",3=>"Fall"];

$test_id = (int)($_GET["id"] ?? 0);
if ($test_id <= 0) redirect("/uiu_brainnext/teacher/teacher_tests.php");

/* ---------------- DB helpers ---------------- */
function db_has_col(mysqli $conn, string $table, string $col): bool {
  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = ?
      AND COLUMN_NAME  = ?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("ss", $table, $col);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}

function db_has_table(mysqli $conn, string $table): bool {
  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = ?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("s", $table);
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

function dedent_block(string $s): string {
  $s = str_replace(["\r\n", "\r"], "\n", (string)$s);

  // remove leading blank lines
  $s = preg_replace("/^\n+/", "", $s);

  // tabs make big left gap in pre-wrap output
  $s = str_replace("\t", "  ", $s);

  $lines = explode("\n", $s);

  // minimum indentation among non-empty lines
  $min = null;
  foreach ($lines as $ln) {
    if (trim($ln) === "") continue;
    preg_match('/^( *)/', $ln, $m);
    $ind = strlen($m[1] ?? "");
    $min = ($min === null) ? $ind : min($min, $ind);
  }

  // remove indentation
  if ($min && $min > 0) {
    foreach ($lines as $i => $ln) {
      $lines[$i] = preg_replace('/^ {0,' . $min . '}/', '', $ln);
    }
  }

  return rtrim(implode("\n", $lines));
}

/* ---------------- detect TEST columns ---------------- */
$test_title_col = pick_col($conn, "tests", ["title","test_title","name"]) ?: "title";
$test_q_col     = pick_col($conn, "tests", ["question_text","question","prompt","statement"]) ?: "question_text";
$test_total_col = pick_col($conn, "tests", ["total_marks","marks","total"]) ?: "total_marks";

$created_col = pick_col($conn, "tests", ["created_by","teacher_id","instructor_id","user_id"]);

$due_col   = pick_col($conn, "tests", ["due_at","due","due_date","due_datetime"]);
$end_col   = pick_col($conn, "tests", ["end_time","ends_at","end_at","end_datetime","close_time"]);
$start_col = pick_col($conn, "tests", ["start_time","starts_at","start_at","start_datetime","open_time"]);

/* ---------------- submissions table detect ---------------- */
$sub_table = null;
if (db_has_table($conn, "test_submissions")) $sub_table = "test_submissions";
else if (db_has_table($conn, "test_answers")) $sub_table = "test_answers";

if (!$sub_table) {
  set_flash("err", "Submission table not found (test_submissions / test_answers).");
  redirect("/uiu_brainnext/teacher/teacher_tests.php");
}

$sub_user_col = db_has_col($conn, $sub_table, "student_id") ? "student_id"
              : (db_has_col($conn, $sub_table, "user_id") ? "user_id"
              : (db_has_col($conn, $sub_table, "studentID") ? "studentID" : "student_id"));

$sub_time_col = db_has_col($conn, $sub_table, "submitted_at") ? "submitted_at"
              : (db_has_col($conn, $sub_table, "created_at") ? "created_at"
              : (db_has_col($conn, $sub_table, "time") ? "time" : "submitted_at"));

$sub_ans_col  = db_has_col($conn, $sub_table, "answer_text") ? "answer_text"
              : (db_has_col($conn, $sub_table, "answer") ? "answer" : "answer_text");

$sub_score_col= db_has_col($conn, $sub_table, "score") ? "score"
              : (db_has_col($conn, $sub_table, "marks") ? "marks" : null);

$sub_status_col = db_has_col($conn, $sub_table, "status") ? "status" : null;

/* auto-judge extra columns (only if you added them) */
$sub_code_col = db_has_col($conn, $sub_table, "source_code") ? "source_code" : null;
$sub_lang_col = db_has_col($conn, $sub_table, "language") ? "language" : null;
$sub_ver_col  = db_has_col($conn, $sub_table, "verdict") ? "verdict" : null;
$sub_msg_col  = db_has_col($conn, $sub_table, "judge_message") ? "judge_message"
             : (db_has_col($conn, $sub_table, "message") ? "message" : null);

/* ---------------- POST (manual check) ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $sub_id = (int)($_POST["sub_id"] ?? 0);
  $score  = (int)($_POST["score"] ?? -1);

  // verify test belongs to teacher
  if (!$created_col) {
    set_flash("err","Your tests table has no created_by/teacher_id column. Cannot verify owner.");
    redirect("/uiu_brainnext/teacher/teacher_test_view.php?id=".$test_id);
  }

  $chk = $conn->prepare("
    SELECT ts.id, t.`$test_total_col` AS total_marks
    FROM $sub_table ts
    JOIN tests t ON t.id = ts.test_id
    WHERE ts.id=? AND t.id=? AND t.`$created_col`=?
    LIMIT 1
  ");
  $chk->bind_param("iii", $sub_id, $test_id, $tid);
  $chk->execute();
  $row = $chk->get_result()->fetch_assoc();

  if (!$row) {
    set_flash("err","You cannot check this submission.");
    redirect("/uiu_brainnext/teacher/teacher_test_view.php?id=".$test_id);
  }

  $max = (int)($row["total_marks"] ?? 0);
  if ($score < 0) $score = 0;
  if ($score > $max) $score = $max;

  if ($sub_score_col) {
    $sqlUp = "UPDATE $sub_table SET `$sub_score_col`=?";
    if ($sub_status_col) $sqlUp .= ", `$sub_status_col`='Checked'";
    $sqlUp .= " WHERE id=?";

    $up = $conn->prepare($sqlUp);
    $up->bind_param("ii", $score, $sub_id);
    $up->execute();

    set_flash("ok","Checked.");
  } else {
    set_flash("err","This submission table has no score column.");
  }

  redirect("/uiu_brainnext/teacher/teacher_test_view.php?id=".$test_id);
}

/* ---------------- load TEST ---------------- */
if (!$created_col) {
  set_flash("err","Your tests table has no created_by/teacher_id column. Add it or update schema.");
  redirect("/uiu_brainnext/teacher/teacher_tests.php");
}

$selDue = "NULL AS due_dt";
if ($due_col) $selDue = "t.`$due_col` AS due_dt";
else if ($end_col) $selDue = "t.`$end_col` AS due_dt";

$selStart = ($start_col ? "t.`$start_col` AS start_dt" : "NULL AS start_dt");
$selEnd   = ($end_col ? "t.`$end_col` AS end_dt" : "NULL AS end_dt");

$stmt = $conn->prepare("
  SELECT
    t.*,
    $selDue,
    $selStart,
    $selEnd,
    c.code AS course_code,
    c.title AS course_title,
    s.section_label,
    s.trimester,
    s.year
  FROM tests t
  JOIN sections s ON s.id = t.section_id
  JOIN courses c ON c.id = s.course_id
  WHERE t.id=? AND t.`$created_col`=?
  LIMIT 1
");
$stmt->bind_param("ii", $test_id, $tid);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();
if (!$test) redirect("/uiu_brainnext/teacher/teacher_tests.php");

/* ---------------- auto-judge folder detect ---------------- */
$tc_dir = testcases_dir_for_test($test_id); // expected: /judge/testcases/test_{id}/
$is_autojudge = false;
$tc_count = 0;

if (is_dir($tc_dir)) {
  if (is_dir($tc_dir . "/inputs") && is_dir($tc_dir . "/outputs")) {
    $ins = glob($tc_dir . "/inputs/*");
    $outs= glob($tc_dir . "/outputs/*");
    $tc_count = min(count($ins ?: []), count($outs ?: []));
    $is_autojudge = ($tc_count > 0);
  } else {
    $ins = glob($tc_dir . "/*.in");
    $outs= glob($tc_dir . "/*.out");
    $tc_count = min(count($ins ?: []), count($outs ?: []));
    $is_autojudge = ($tc_count > 0);
  }
}

$tname = $TRI[(int)($test["trimester"] ?? 0)] ?? ("T".(int)($test["trimester"] ?? 0));

/* ---------------- load SUBMISSIONS ---------------- */
$cols = [];
$cols[] = "ts.id AS id";
$cols[] = "ts.`$sub_ans_col` AS answer_text";
$cols[] = "ts.`$sub_time_col` AS submitted_at";
if ($sub_status_col) $cols[] = "ts.`$sub_status_col` AS status";
if ($sub_score_col)  $cols[] = "ts.`$sub_score_col` AS score";
if ($sub_code_col)   $cols[] = "ts.`$sub_code_col` AS source_code";
if ($sub_lang_col)   $cols[] = "ts.`$sub_lang_col` AS language";
if ($sub_ver_col)    $cols[] = "ts.`$sub_ver_col` AS verdict";
if ($sub_msg_col)    $cols[] = "ts.`$sub_msg_col` AS judge_message";

$stmt2 = $conn->prepare("
  SELECT " . implode(", ", $cols) . ",
         u.username AS student_username,
         u.full_name AS student_name
  FROM $sub_table ts
  JOIN users u ON u.id = ts.`$sub_user_col`
  WHERE ts.test_id=?
  ORDER BY ts.id DESC
");
$stmt2->bind_param("i", $test_id);
$stmt2->execute();
$res2 = $stmt2->get_result();
$subs = [];
while ($r = $res2->fetch_assoc()) $subs[] = $r;

/* ---------------- UI ---------------- */
$err = get_flash("err");
$ok  = get_flash("ok");

$testTitle = (string)($test[$test_title_col] ?? ("Test #".$test_id));
$testQ     = (string)($test[$test_q_col] ?? "");
$testQ = preg_replace('/^[\t ]+/m', '', $testQ); // remove left indent per line
$testTotal = (int)($test[$test_total_col] ?? 0);

$dueShown = fmt_dt($test["due_dt"] ?? "");
$startShown = isset($test["start_dt"]) ? fmt_dt($test["start_dt"]) : "-";
$endShown   = isset($test["end_dt"]) ? fmt_dt($test["end_dt"]) : "-";
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Test View</title>
  <link rel="stylesheet" href="/uiu_brainnext/assets/css/style.css">

  <style>
  .code-pre{
    margin: 0;
    margin-top: 8px;
    padding: 10px 10px 10px 10px;  
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,.08);
    background: rgba(10,15,25,.35);
    white-space: pre-wrap;
    word-break: break-word;
    line-height: 1.55;
    font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
    font-size: 13px;
  }
</style>

</head>
<body>
<div class="container">
  <div class="nav">
    <div class="left">
      <a class="badge" href="/uiu_brainnext/teacher/teacher_tests.php">← My Tests</a>
      <div class="muted">
        <?=e((string)($test["course_code"] ?? ""))?>-<?=e((string)($test["section_label"] ?? ""))?>
        (<?=e($tname)?>/<?=e((string)($test["year"] ?? ""))?>)
      </div>
    </div>
    <a class="badge" href="/uiu_brainnext/logout.php">Logout</a>
  </div>

  <?php if ($err): ?><div class="alert err"><?=e($err)?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert ok"><?=e($ok)?></div><?php endif; ?>

  <div class="card">
    <h3><?=e($testTitle)?></h3>

    <div class="muted">
      Total Marks: <b><?= (int)$testTotal ?></b>
      • Due: <?= e($dueShown) ?>
      <?php if ($startShown !== "-" || $endShown !== "-"): ?>
        • Start: <?= e($startShown) ?> • End: <?= e($endShown) ?>
      <?php endif; ?>
    </div>

    <div style="height:10px"></div>

    <?php if ($is_autojudge): ?>
      <div class="card" style="padding:12px; border:1px solid rgba(0,180,90,.25); background:rgba(0,180,90,.06);">
        <b>Auto-Judge Enabled</b>
       <div class="muted">Hidden testcases found: <b><?= (int)$tc_count ?></b></div>
        <?php if (!$sub_ver_col): ?>
          <div class="muted" style="margin-top:6px;">
            ⚠️ Your <b><?= e($sub_table) ?></b> table has NO <b>verdict</b>/<b>judge_message</b> columns,
            so teacher view cannot show AC/WA unless you add these columns.
          </div>
        <?php endif; ?>
      </div>
      <div style="height:10px"></div>
    <?php endif; ?>

    <label class="muted">Question</label>
    <div class="card" style="padding:12px; white-space:normal; line-height:1.6;">
      <?= nl2br(e(trim($testQ))) ?>
    </div>

  </div>

  <div style="height:14px"></div>

  <div class="card">
    <h3>Submissions</h3>

    <?php if (count($subs) === 0): ?>
      <div class="muted">No submissions yet.</div>
    <?php else: ?>
      <?php foreach ($subs as $s): ?>
        <?php
          $status = (string)($s["status"] ?? "Submitted");
          $score  = $s["score"] ?? null;

          $verdict = strtoupper((string)($s["verdict"] ?? ""));
          $jmsg    = (string)($s["judge_message"] ?? "");

          $showCode = ($sub_code_col && !empty($s["source_code"]));
          $answerText = (string)($s["answer_text"] ?? "");
          $codeText   = (string)($s["source_code"] ?? "");
        ?>
        <div class="card" style="margin-top:12px;">
          <div class="muted">
            <b><?=e($s["student_username"])?></b> - <?=e($s["student_name"])?>
            • <?=e((string)($s["submitted_at"] ?? ""))?>
            • Status: <b><?=e($status)?></b>
            • Score: <b><?= e($score === null ? "-" : (string)$score) ?></b>
            <?php if ($verdict !== ""): ?>
              • Verdict: <b><?= e($verdict) ?></b>
            <?php endif; ?>
          </div>

          <?php if ($jmsg !== ""): ?>
            <div class="muted" style="margin-top:8px; white-space:pre-wrap;"><?= e($jmsg) ?></div>
          <?php endif; ?>

          <div style="height:10px"></div>

          <label class="muted"><?= $showCode ? "Source Code" : "Answer" ?></label>

          <?php
            $rawShow = $showCode ? $codeText : $answerText;
            $rawShow = dedent_block((string)$rawShow);
          ?>

          <pre class="code-pre"><?= e($rawShow) ?></pre>


          <div style="height:10px"></div>

          <?php if ($sub_score_col): ?>

        <?php
          $isChecked = (strtoupper(trim($status)) === "CHECKED");
          $isAC = ($verdict === "AC");
        ?>

        <?php if ($isChecked && $isAC): ?>
          <div class="muted" style="margin-top:6px;">
            ✅ Auto-checked (AC). Score was set automatically. Teacher manual check not required.
          </div>
        <?php else: ?>
          <form method="POST" class="grid">
            <input type="hidden" name="sub_id" value="<?= (int)$s["id"] ?>">
            <div class="col-6">
              <label class="muted">Give Score (0 to <?= (int)$testTotal ?>)</label>
              <input
                name="score"
                type="number"
                min="0"
                max="<?= (int)$testTotal ?>"
                value="<?= (int)($score === null ? $testTotal : $score) ?>"
                required
              >
            </div>
            <div class="col-6" style="display:flex; align-items:flex-end;">
              <button type="submit">Mark Checked</button>
            </div>
          </form>
        <?php endif; ?>

<?php else: ?>
  <div class="muted">No score column found in <?= e($sub_table) ?>.</div>
<?php endif; ?>

        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
