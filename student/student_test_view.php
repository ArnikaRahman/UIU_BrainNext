<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* ✅ IMPORTANT: Fix timezone mismatch (Dhaka) */
date_default_timezone_set("Asia/Dhaka");

/* ---------------- helpers ---------------- */
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
function normalize_dt_db(?string $v): string {
  $v = trim((string)$v);
  if ($v === "" || $v === "0000-00-00 00:00:00") return "";
  return $v;
}
function fmt_dt_local(string $dbdt): string {
  if ($dbdt === "" || $dbdt === "0000-00-00 00:00:00") return "";
  $ts = strtotime($dbdt);
  if (!$ts) return $dbdt;
  return date("d M Y, h:i A", $ts);
}
function ensure_dir(string $dir): void { if (!is_dir($dir)) @mkdir($dir, 0777, true); }
function rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  foreach ($items as $it) {
    if ($it === "." || $it === "..") continue;
    $p = $dir . DIRECTORY_SEPARATOR . $it;
    if (is_dir($p)) rrmdir($p); else @unlink($p);
  }
  @rmdir($dir);
}
function normalize_output(string $s): string {
  $s = str_replace("\r\n", "\n", $s);
  $s = trim($s);
  $lines = explode("\n", $s);
  $out = [];
  foreach ($lines as $ln) {
    $ln = trim(preg_replace('/[ \t]+/', ' ', $ln));
    $out[] = $ln;
  }
  return trim(implode("\n", $out));
}
function run_process_with_timeout(array $cmd, string $stdin, int $timeoutSec): array {
  $descriptorspec = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"],
  ];

  $process = @proc_open($cmd, $descriptorspec, $pipes, null, null);
  if (!is_resource($process)) {
    return [
      "ok" => false,
      "code" => -1,
      "stdout" => "",
      "stderr" => "Failed to start process. (proc_open disabled or compiler not found)"
    ];
  }

  fwrite($pipes[0], $stdin);
  fclose($pipes[0]);

  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);

  $stdout = "";
  $stderr = "";
  $start = time();

  while (true) {
    $status = proc_get_status($process);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);

    if (!$status["running"]) break;

    if ((time() - $start) >= $timeoutSec) {
      @proc_terminate($process);
      @proc_close($process);
      return ["ok" => false, "code" => -2, "stdout" => $stdout, "stderr" => $stderr, "timeout" => true];
    }
    usleep(100000);
  }

  fclose($pipes[1]);
  fclose($pipes[2]);

  $exit = proc_close($process);
  return ["ok" => true, "code" => $exit, "stdout" => $stdout, "stderr" => $stderr];
}

/**
 * ✅ OPTION B: Judge from extracted folder
 * Folder: judge/testcases/test_{id}/
 * Supports:
 *  - inputs/ + outputs/
 *  - 1.in + 1.out style
 */
function judge_folder_testcases(string $dir, string $lang, string $code, int $timeLimitSec = 2): array {
  if (!is_dir($dir)) return ["verdict" => "ERR", "message" => "Hidden testcase folder not found."];

  $tests = [];

  $inputsDir = $dir . DIRECTORY_SEPARATOR . "inputs";
  $outputsDir = $dir . DIRECTORY_SEPARATOR . "outputs";

  if (is_dir($inputsDir) && is_dir($outputsDir)) {
    $inFiles = array_values(array_filter(scandir($inputsDir), fn($x)=>$x!=='.' && $x!=='..'));
    sort($inFiles, SORT_NATURAL);
    foreach ($inFiles as $f) {
      $inPath = $inputsDir . DIRECTORY_SEPARATOR . $f;
      if (!is_file($inPath)) continue;

      // outputs can be same filename or same number.out
      $outPath = $outputsDir . DIRECTORY_SEPARATOR . $f;
      if (!file_exists($outPath)) {
        $alt = $outputsDir . DIRECTORY_SEPARATOR . preg_replace('/\.[^.]+$/', '.out', $f);
        if (file_exists($alt)) $outPath = $alt;
      }
      if (file_exists($outPath) && is_file($outPath)) {
        $tests[] = ["in" => $inPath, "out" => $outPath, "name" => $f];
      }
    }
  } else {
    $files = array_values(array_filter(scandir($dir), fn($x)=>$x!=='.' && $x!=='..'));
    $inList = [];
    foreach ($files as $f) if (preg_match('/^\d+\.in$/', $f)) $inList[] = $f;
    sort($inList, SORT_NATURAL);

    foreach ($inList as $f) {
      $n = preg_replace('/\.in$/', '', $f);
      $inPath = $dir . DIRECTORY_SEPARATOR . $f;
      $outPath = $dir . DIRECTORY_SEPARATOR . $n . ".out";
      if (file_exists($outPath) && is_file($outPath)) {
        $tests[] = ["in"=>$inPath,"out"=>$outPath,"name"=>$n];
      }
    }
  }

  if (count($tests) === 0) return ["verdict" => "ERR", "message" => "No testcases found in hidden folder."];

  $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "uiu_judge_" . uniqid();
  $work = $base . DIRECTORY_SEPARATOR . "work";
  ensure_dir($work);

  $src = $work . DIRECTORY_SEPARATOR . (($lang === "c") ? "main.c" : "main.cpp");
  file_put_contents($src, $code);

  $exe = $work . DIRECTORY_SEPARATOR . ((strtoupper(substr(PHP_OS,0,3)) === "WIN") ? "a.exe" : "a.out");

  $compileCmd = ($lang === "c")
    ? ["gcc", $src, "-O2", "-std=c11", "-o", $exe]
    : ["g++", $src, "-O2", "-std=c++17", "-o", $exe];

  $comp = run_process_with_timeout($compileCmd, "", 10);
  if (!($comp["ok"] ?? false) || ($comp["code"] ?? 1) !== 0 || !file_exists($exe)) {
    $msg = trim(($comp["stderr"] ?? "") . "\n" . ($comp["stdout"] ?? ""));
    rrmdir($base);
    return ["verdict" => "CE", "message" => ($msg !== "" ? $msg : "Compilation failed.")];
  }

  $passed = 0;
  foreach ($tests as $i => $tc) {
    $in  = (string)@file_get_contents($tc["in"]);
    $exp = (string)@file_get_contents($tc["out"]);

    $run = run_process_with_timeout([$exe], $in, $timeLimitSec);
    if (($run["timeout"] ?? false) === true) {
      rrmdir($base);
      return ["verdict"=>"TLE","message"=>"Time Limit Exceeded on testcase ".($i+1),"passed"=>$passed,"total"=>count($tests)];
    }
    if (!($run["ok"] ?? false)) {
      rrmdir($base);
      return ["verdict"=>"RE","message"=>"Runtime Error on testcase ".($i+1),"passed"=>$passed,"total"=>count($tests)];
    }

    $got = normalize_output((string)($run["stdout"] ?? ""));
    $ex2 = normalize_output((string)$exp);

    if ($got === $ex2) $passed++;
    else {
      rrmdir($base);
      return ["verdict"=>"WA","message"=>"Wrong Answer on testcase ".($i+1),"passed"=>$passed,"total"=>count($tests)];
    }
  }

  rrmdir($base);
  return ["verdict"=>"AC","message"=>"Accepted (".$passed."/".count($tests).")","passed"=>$passed,"total"=>count($tests)];
}

/* ---------------- user ---------------- */
$user_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($user_id <= 0) redirect("/uiu_brainnext/logout.php");

$test_id = (int)($_GET["id"] ?? 0);
if ($test_id <= 0) {
  set_flash("err", "Invalid test id.");
  redirect("/uiu_brainnext/student/student_tests.php");
}

/* ---------------- schema detection ---------------- */
$has_tests    = db_has_table($conn, "tests");
$has_sections = db_has_table($conn, "sections");
$has_courses  = db_has_table($conn, "courses");
$has_samples  = db_has_table($conn, "test_samples");

$sub_table = null;
if (db_has_table($conn, "test_submissions")) $sub_table = "test_submissions";
else if (db_has_table($conn, "test_answers")) $sub_table = "test_answers";

$sub_user_col = $sub_table ? (db_has_col($conn, $sub_table, "user_id") ? "user_id" : (db_has_col($conn, $sub_table, "student_id") ? "student_id" : "user_id")) : null;
$sub_time_col = $sub_table ? (db_has_col($conn, $sub_table, "submitted_at") ? "submitted_at" : (db_has_col($conn, $sub_table, "created_at") ? "created_at" : "submitted_at")) : null;

$sub_ans_col   = $sub_table ? (db_has_col($conn, $sub_table, "answer_text") ? "answer_text" : (db_has_col($conn, $sub_table, "answer") ? "answer" : "answer_text")) : null;
$sub_code_col  = $sub_table ? (db_has_col($conn, $sub_table, "source_code") ? "source_code" : null) : null;
$sub_lang_col  = $sub_table ? (db_has_col($conn, $sub_table, "language") ? "language" : null) : null;
$sub_ver_col   = $sub_table ? (db_has_col($conn, $sub_table, "verdict") ? "verdict" : null) : null;
$sub_score_col = $sub_table ? (db_has_col($conn, $sub_table, "score") ? "score" : (db_has_col($conn, $sub_table, "marks") ? "marks" : null)) : null;
$sub_status_col= $sub_table ? (db_has_col($conn, $sub_table, "status") ? "status" : null) : null;
$sub_msg_col   = $sub_table ? (db_has_col($conn, $sub_table, "judge_message") ? "judge_message" : (db_has_col($conn, $sub_table, "message") ? "message" : null)) : null;

$title_col = pick_col($conn, "tests", ["title", "test_title", "name"]);
$q_col     = pick_col($conn, "tests", ["question_text", "question", "prompt", "statement"]);
$desc_col  = pick_col($conn, "tests", ["description", "instructions"]);
$total_col = pick_col($conn, "tests", ["total_marks", "marks", "total"]);

$start_col = pick_col($conn, "tests", ["start_time","start_at","starts_at","start_datetime","start_date_time"]);
$end_col   = pick_col($conn, "tests", ["end_time","end_at","ends_at","end_datetime","end_date_time"]);
$has_time_window = ($start_col !== null && $end_col !== null);

if (!$has_tests) {
  set_flash("err", "Tests table not found.");
  redirect("/uiu_brainnext/student/student_tests.php");
}
if (!$sub_table || !$sub_user_col || !$sub_time_col) {
  set_flash("err", "Submissions table not found or missing required columns.");
  redirect("/uiu_brainnext/student/student_tests.php");
}

/* ---------------- load test ---------------- */
$selCols = ["t.*"];
if (db_has_col($conn, "tests", "section_id") && $has_sections && $has_courses) {
  $selCols[] = "c.code AS course_code";
  $selCols[] = "c.title AS course_title";
  $selCols[] = "s.section_label";
  $selCols[] = "s.trimester";
  $selCols[] = "s.year";
}

$selStart = ($start_col ? "t.`$start_col` AS start_dt" : "NULL AS start_dt");
$selEnd   = ($end_col ? "t.`$end_col` AS end_dt" : "NULL AS end_dt");

$sqlTest = "SELECT ".implode(", ", $selCols).", $selStart, $selEnd
            FROM tests t ";
if (db_has_col($conn, "tests", "section_id") && $has_sections && $has_courses) {
  $sqlTest .= "JOIN sections s ON s.id = t.section_id
               JOIN courses c ON c.id = s.course_id ";
}
$sqlTest .= "WHERE t.id=? LIMIT 1";

$stT = $conn->prepare($sqlTest);
$stT->bind_param("i", $test_id);
$stT->execute();
$test = $stT->get_result()->fetch_assoc();

if (!$test) {
  set_flash("err", "Test not found.");
  redirect("/uiu_brainnext/student/student_tests.php");
}

/* ---------------- time window check (Dhaka time) ---------------- */
$time_state = "open"; // open/before/ended
$start_dt = "";
$end_dt = "";

if ($has_time_window) {
  $start_dt = normalize_dt_db((string)($test["start_dt"] ?? ""));
  $end_dt   = normalize_dt_db((string)($test["end_dt"] ?? ""));

  if ($start_dt !== "" && $end_dt !== "") {
    $now_ts = time();
    $start_ts = strtotime($start_dt);
    $end_ts   = strtotime($end_dt);

    if ($start_ts && $now_ts < $start_ts) $time_state = "before";
    else if ($end_ts && $now_ts > $end_ts) $time_state = "ended";
  }
}

/* ---------------- samples ---------------- */
$samples = [];
if ($has_samples) {
  $stS = $conn->prepare("SELECT sample_input, sample_output FROM test_samples WHERE test_id=? ORDER BY id ASC");
  $stS->bind_param("i", $test_id);
  $stS->execute();
  $rS = $stS->get_result();
  while ($rS && ($row = $rS->fetch_assoc())) $samples[] = $row;
}

/* ---------------- detect autojudge folder ---------------- */
$judge_dir = __DIR__ . "/../judge/testcases/test_" . (int)$test_id;
$autojudge = is_dir($judge_dir) && $sub_code_col && $sub_lang_col && $sub_ver_col && $sub_score_col;

/* ---------------- fetch my last submission ---------------- */
$cols = [];
$cols[] = "id";
if ($sub_ans_col)  $cols[] = "$sub_ans_col AS ans";
if ($sub_code_col) $cols[] = "$sub_code_col AS code";
if ($sub_lang_col) $cols[] = "$sub_lang_col AS lang";
if ($sub_ver_col)  $cols[] = "$sub_ver_col AS ver";
if ($sub_score_col)$cols[] = "$sub_score_col AS score";
if ($sub_msg_col)  $cols[] = "$sub_msg_col AS msg";

$sqlMe = "SELECT " . implode(", ", $cols) . "
          FROM $sub_table
          WHERE test_id = ? AND $sub_user_col = ?
          ORDER BY id DESC LIMIT 1";
$stM = $conn->prepare($sqlMe);
$stM->bind_param("ii", $test_id, $user_id);
$stM->execute();
$me = $stM->get_result()->fetch_assoc();
$already_submitted = false;
if ($me) {
  $already_submitted = true;
}

$my_lang = $me ? (string)($me["lang"] ?? "cpp") : "cpp";
$my_code = $me ? (string)($me["code"] ?? "") : "";
$my_answer = $me ? (string)($me["ans"] ?? "") : "";

$my_last_verdict = $me ? (string)($me["ver"] ?? "") : "";
$my_last_score   = $me ? (string)($me["score"] ?? "") : "";
$my_last_msg     = $me ? (string)($me["msg"] ?? "") : "";
$my_last_time    = "";

/* last time */
$stTT = $conn->prepare("SELECT $sub_time_col AS t FROM $sub_table WHERE test_id=? AND $sub_user_col=? ORDER BY id DESC LIMIT 1");
$stTT->bind_param("ii", $test_id, $user_id);
$stTT->execute();
$tmpT = $stTT->get_result()->fetch_assoc();
if ($tmpT) $my_last_time = fmt_dt_local((string)($tmpT["t"] ?? ""));

/* ---------------- submit ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if ($already_submitted) {
    set_flash("err", "You already submitted this test.");
    redirect("/uiu_brainnext/student/student_test_view.php?id=" . $test_id);
  }

  if ($time_state !== "open") {
    set_flash("err", "Test is not open for submission.");
    redirect("/uiu_brainnext/student/student_test_view.php?id=" . $test_id);
  }

  $now = date("Y-m-d H:i:s");

  // AutoJudge
  if ($autojudge) {
    $lang = strtolower(trim((string)($_POST["language"] ?? "cpp")));
    $code = trim((string)($_POST["source_code"] ?? ""));

    if (!in_array($lang, ["c", "cpp"], true)) {
      set_flash("err", "Only C / C++ are allowed.");
      redirect("/uiu_brainnext/student/student_test_view.php?id=" . $test_id);
    }
    if ($code === "") {
      set_flash("err", "Source code cannot be empty.");
      redirect("/uiu_brainnext/student/student_test_view.php?id=" . $test_id);
    }

    $res = judge_folder_testcases($judge_dir, $lang, $code, 2);
    $verdict = strtoupper((string)($res["verdict"] ?? "ERR"));
    $msg = (string)($res["message"] ?? "");

    $total_marks = $total_col ? (int)($test[$total_col] ?? 0) : 0;

    // ✅ Partial scoring: based on passed/total cases (teacher can override later)
    $passed_cases = isset($res["passed"]) ? (int)$res["passed"] : 0;
    $total_cases  = isset($res["total"])  ? (int)$res["total"]  : 0;

    // If judge didn't report total (CE/ERR), try to count from folder quickly
    if ($total_cases <= 0) {
      $tcInputs = [];
      if (is_dir($judge_dir . "/inputs")) $tcInputs = glob($judge_dir . "/inputs/*") ?: [];
      else $tcInputs = glob($judge_dir . "/*.in") ?: [];
      $total_cases = count($tcInputs);
    }

    $score = 0;
    if ($total_marks > 0 && $total_cases > 0) {
      $score = (int)floor(($passed_cases / $total_cases) * $total_marks);
      if ($score < 0) $score = 0;
      if ($score > $total_marks) $score = $total_marks;
    }

    // ✅ Auto-check only if AC, else keep as Submitted (manual review allowed)
    $status_val = ($verdict === "AC") ? "Checked" : "Submitted";
    if ($verdict === "AC") $score = $total_marks;

    // ✅ Helpful message includes pass ratio (no test data revealed)
    if ($total_cases > 0) {
      $msg = trim($msg);
      $msg .= ($msg !== "" ? "\n" : "") . "Passed: {$passed_cases}/{$total_cases}";
    }

    $cols = ["test_id", $sub_user_col, $sub_time_col];
    $vals = ["?", "?", "?"];
    $types = "iis";
    $params = [$test_id, $user_id, $now];

    if ($sub_code_col)  { $cols[] = $sub_code_col;  $vals[] = "?"; $types .= "s"; $params[] = $code; }
    if ($sub_lang_col)  { $cols[] = $sub_lang_col;  $vals[] = "?"; $types .= "s"; $params[] = $lang; }
    if ($sub_status_col){ $cols[] = $sub_status_col; $vals[] = "?"; $types .= "s"; $params[] = $status_val; }
    if ($sub_ver_col)   { $cols[] = $sub_ver_col;   $vals[] = "?"; $types .= "s"; $params[] = $verdict; }
    if ($sub_score_col) { $cols[] = $sub_score_col; $vals[] = "?"; $types .= "i"; $params[] = (int)$score; }
    if ($sub_msg_col)   { $cols[] = $sub_msg_col;   $vals[] = "?"; $types .= "s"; $params[] = $msg; }

    $sqlIns = "INSERT INTO $sub_table (".implode(",", $cols).") VALUES (".implode(",", $vals).")";
    $ins = $conn->prepare($sqlIns);

    $bind = [];
    $bind[] = $types;
    for ($i=0; $i<count($params); $i++) $bind[] = &$params[$i];
    call_user_func_array([$ins, "bind_param"], $bind);

    if (!$ins->execute()) {
      set_flash("err", "Submit failed: " . $conn->error);
      redirect("/uiu_brainnext/student/student_test_view.php?id=" . $test_id);
    }

    set_flash("ok", "Submitted. Verdict: " . $verdict . " • Score: " . (int)$score . "/" . (int)$total_marks);
    redirect("/uiu_brainnext/student/student_test_view.php?id=" . $test_id);
  }

  // Manual
  $ans = trim((string)($_POST["answer_text"] ?? ""));
  if ($ans === "") {
    set_flash("err", "Answer cannot be empty.");
    redirect("/uiu_brainnext/student/student_test_view.php?id=" . $test_id);
  }

  $sqlIns = "INSERT INTO $sub_table (test_id, $sub_user_col, $sub_ans_col, $sub_time_col)
             VALUES (?, ?, ?, ?)";
  $ins = $conn->prepare($sqlIns);
  $ins->bind_param("iiss", $test_id, $user_id, $ans, $now);

  if (!$ins->execute()) {
    set_flash("err", "Submit failed: " . $conn->error);
    redirect("/uiu_brainnext/student/student_test_view.php?id=" . $test_id);
  }

  set_flash("ok", "Submitted successfully.");
  redirect("/uiu_brainnext/student/student_test_view.php?id=" . $test_id);
}

/* ---------------- UI ---------------- */
$TRI = [1=>"Spring",2=>"Summer",3=>"Fall"];
$course_code = (string)($test["course_code"] ?? "");
$course_title = (string)($test["course_title"] ?? "");
$section_label = (string)($test["section_label"] ?? "");
$tri = (int)($test["trimester"] ?? 0);
$year = (string)($test["year"] ?? "");

$title = $title_col ? (string)($test[$title_col] ?? "") : ("Test #" . $test_id);
$qtext = $q_col ? (string)($test[$q_col] ?? "") : "";
$desc  = $desc_col ? (string)($test[$desc_col] ?? "") : "";
$total_marks = $total_col ? (int)($test[$total_col] ?? 0) : 0;

ui_start("Test", "Student Panel");
ui_top_actions([
  ["Dashboard", "/student/dashboard.php"],
  ["Tests", "/student/student_tests.php"],
]);

$err = get_flash("err");
$ok  = get_flash("ok");
if ($err) echo '<div class="alert err">'.e($err).'</div>';
if ($ok)  echo '<div class="alert ok">'.e($ok).'</div>';

$tn = $TRI[$tri] ?? ($tri ? ("T".$tri) : "");
$metaLine = trim($course_code . " - " . $course_title, " -");
$metaLine2 = trim(($section_label ? ("Section ".$section_label) : "") . ($tn ? (" • ".$tn) : "") . ($year ? (" / ".$year) : ""), " •/");

$locked = ($time_state === "before" || $time_state === "ended" || $already_submitted); // ✅ NEW: also lock after submit
$lockedMsg = "";
if ($already_submitted) $lockedMsg = "You already submitted this test. Re-submission is locked.";
else if ($time_state === "before") $lockedMsg = "Test not started yet.";
else if ($time_state === "ended")  $lockedMsg = "Time is over. Test ended.";

$remain = 0;
if ($time_state === "open" && isset($end_ts) && $end_ts) $remain = max(0, $end_ts - time());
?>

<style>
.test-head{padding:18px;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(10,15,25,.20);}
.test-meta{opacity:.85;margin-bottom:8px;}
.timebox{margin-top:12px;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.05);display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between;}
.timepill{display:inline-flex;gap:10px;align-items:center;font-weight:900;padding:7px 10px;border-radius:999px;border:1px solid rgba(80,140,255,.30);background:rgba(80,140,255,.12);}
.timewarn{border-color:rgba(255,180,0,.35) !important;background:rgba(255,180,0,.10) !important;}
.timebad{border-color:rgba(255,80,80,.35) !important;background:rgba(255,80,80,.10) !important;}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}
.submit-disabled{opacity:.65;pointer-events:none;}
.verbox{margin-top:10px;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(10,15,25,.20);}
.pill{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:900;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);}
.pill-ac{border-color:rgba(0,180,90,.35);background:rgba(0,180,90,.12);}
.pill-wa{border-color:rgba(255,180,0,.35);background:rgba(255,180,0,.10);}
.pill-bad{border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.10);}
</style>

<div class="card">
  <div class="test-head">
    <div class="test-meta">
      <?= e($metaLine) ?><?= $metaLine2 ? " • " . e($metaLine2) : "" ?>
    </div>
    <h3 style="margin:0;"><?= e($title) ?></h3>

    <?php if ($desc !== ""): ?>
      <div class="muted" style="margin-top:10px; white-space:pre-wrap;"><?= e($desc) ?></div>
    <?php endif; ?>

    <?php if ($qtext !== ""): ?>
      <div class="card" style="margin-top:12px; padding:14px 16px;">
        <div style="font-weight:900;"><?= e($qtext) ?></div>
      </div>
    <?php endif; ?>

    <?php if ($total_marks > 0): ?>
      <div class="muted" style="margin-top:10px;">Total Marks: <b><?= (int)$total_marks ?></b></div>
    <?php endif; ?>

    <div class="muted" style="margin-top:8px;">
      Server Time (Dhaka): <span class="mono"><?= e(date("d M Y, h:i:s A")) ?></span>
    </div>

    <?php if ($autojudge): ?>
      <div class="verbox">
        <div style="font-weight:900;">Auto-Judge Enabled</div>
        <div class="muted">Your code will be compiled and tested against hidden testcases.</div>
      </div>
    <?php endif; ?>

    <?php if ($has_time_window && $start_dt !== "" && $end_dt !== ""): ?>
      <div class="timebox" style="justify-content:flex-end;">
        <div>
          <?php if ($time_state === "open"): ?>
            <span class="timepill" id="countdownPill">Remaining: <span class="mono" id="countdown">--:--:--</span></span>
          <?php elseif ($time_state === "before"): ?>
            <span class="timepill timewarn"><?= e($lockedMsg) ?></span>
          <?php elseif ($time_state === "ended"): ?>
            <span class="timepill timebad"><?= e($lockedMsg) ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($my_last_time !== ""): ?>
      <div class="verbox">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <div class="muted">Last submitted: <span class="mono"><?= e($my_last_time) ?></span></div>

          <?php if ($my_last_verdict !== ""):
            $v = strtoupper($my_last_verdict);
            $cls = "pill";
            if ($v === "AC") $cls .= " pill-ac";
            else if ($v === "WA") $cls .= " pill-wa";
            else if ($v === "CE" || $v === "RE" || $v === "TLE") $cls .= " pill-bad";
          ?>
            <span class="<?= e($cls) ?>"><?= e($v) ?></span>
          <?php endif; ?>

          <?php if ($my_last_score !== ""): ?>
            <span class="pill">Score: <?= e($my_last_score) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($my_last_msg !== ""): ?>
          <div class="muted" style="margin-top:8px; white-space:pre-wrap;"><?= e($my_last_msg) ?></div>
        <?php endif; ?>

        <?php if ($already_submitted): ?>
          <div class="muted" style="margin-top:10px;">
            ✅ You have already submitted this test
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div style="height:14px;"></div>

<div class="card">
  <h3>Sample Cases</h3>

  <?php if (empty($samples)): ?>
    <div class="muted">No sample cases available.</div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Sample Input</th>
          <th>Sample Output</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($samples as $s): ?>
          <tr>
            <td class="mono" style="white-space:pre-wrap;"><?= e((string)$s["sample_input"]) ?></td>
            <td class="mono" style="white-space:pre-wrap;"><?= e((string)$s["sample_output"]) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div style="height:14px;"></div>

<div class="card <?= $locked ? "submit-disabled" : "" ?>">
  <h3>Submit</h3>
  <div class="muted"><?= $autojudge ? "Submit your code." : "Paste your answer/solution here." ?></div>

  <?php if ($locked): ?>
    <div style="height:10px;"></div>
    <div class="alert err"><?= e($lockedMsg) ?></div>
  <?php endif; ?>

  <div style="height:10px;"></div>

  <form method="POST">
    <?php if ($autojudge): ?>
      <label class="label">Language</label>
      <select name="language" <?= $locked ? "disabled" : "" ?>>
        <option value="cpp" <?= $my_lang==="cpp"?"selected":"" ?>>C++</option>
        <option value="c"   <?= $my_lang==="c"?"selected":"" ?>>C</option>
      </select>

      <div style="height:10px;"></div>

      <label class="label">Source Code</label>
      <textarea name="source_code" placeholder="Write your code here..." style="min-height:320px;" <?= $locked ? "disabled" : "" ?>><?= e($my_code) ?></textarea>

      <div style="height:10px;"></div>
      <button class="btn-primary" type="submit" <?= $locked ? "disabled" : "" ?>>Run & Submit</button>
    <?php else: ?>
      <label class="label">Your Answer</label>
      <textarea name="answer_text" placeholder="Write your answer here..." style="min-height:220px;" <?= $locked ? "disabled" : "" ?>><?= e($my_answer) ?></textarea>

      <div style="height:10px;"></div>
      <button class="btn-primary" type="submit" <?= $locked ? "disabled" : "" ?>>Submit</button>
    <?php endif; ?>
  </form>
</div>

<?php ui_end(); ?>














