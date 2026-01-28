<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------- judge include (safe) ---------- */
$judge_file = __DIR__ . "/../includes/judge_local.php";
if (!file_exists($judge_file)) {
  set_flash("err", "Auto judge is not installed (judge_local.php missing).");
  redirect("/uiu_brainnext/student/dashboard.php");
}
require_once $judge_file; // provides local_judge()

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  redirect("/uiu_brainnext/student/dashboard.php");
}

/* ---------- helpers ---------- */
function db_current_name(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db");
  $row = $r ? $r->fetch_assoc() : null;
  return (string)($row["db"] ?? "");
}
function db_has_col(mysqli $conn, string $table, string $col): bool {
  $db = db_current_name($conn);
  if ($db === "") return false;

  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME   = ?
      AND COLUMN_NAME  = ?
    LIMIT 1
  ");
  if (!$st) return false;

  $st->bind_param("sss", $db, $table, $col);
  $st->execute();
  $res = $st->get_result();
  return (bool)$res->fetch_row();
}
function clean_compiler_log(string $txt): string {
  $t = trim($txt);
  if ($t === "") return "Compilation Error";

  // remove huge absolute paths (Windows)
  $t = preg_replace('~[A-Z]:\\\\[^\\s]+\\\\~i', '', $t);

  // normalize lines
  $t = str_replace("\r\n", "\n", $t);
  $t = preg_replace("/\n{3,}/", "\n\n", $t);

  // limit length to keep UI clean
  if (strlen($t) > 2500) $t = substr($t, 0, 2500) . "\n...(trimmed)";
  return $t;
}
function trim_block(string $txt, int $max = 600): string {
  $t = trim($txt);
  if ($t === "") return "";
  $t = str_replace("\r\n", "\n", $t);
  if (strlen($t) > $max) $t = substr($t, 0, $max) . "\n...(trimmed)";
  return $t;
}

/* ---------- input ---------- */
$user_id    = (int)($_SESSION["user"]["id"] ?? 0);
$problem_id = (int)($_POST["problem_id"] ?? 0);
$lang       = strtolower(trim((string)($_POST["language"] ?? "cpp"))); // c / cpp
$code       = (string)($_POST["code"] ?? "");

if ($user_id <= 0 || $problem_id <= 0 || trim($code) === "") {
  set_flash("err", "Invalid submission.");
  redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);
}
if (!in_array($lang, ["c","cpp"], true)) $lang = "cpp";

/* ---------- load points (score) ---------- */
$points = 100; // default
$stP = $conn->prepare("SELECT points FROM problems WHERE id=? LIMIT 1");
$stP->bind_param("i", $problem_id);
$stP->execute();
$pRow = $stP->get_result()->fetch_assoc();
if ($pRow && isset($pRow["points"])) $points = (int)$pRow["points"];

/* ---------- load testcases ---------- */
$cases = [];
$st = $conn->prepare("
  SELECT input_text, output_text
  FROM problem_samples
  WHERE problem_id=?
  ORDER BY id ASC
");
$st->bind_param("i", $problem_id);
$st->execute();
$res = $st->get_result();
while ($row = $res->fetch_assoc()) {
  $cases[] = ["in" => (string)$row["input_text"], "out" => (string)$row["output_text"]];
}

if (empty($cases)) {
  set_flash("err", "No testcases found for this problem.");
  redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);
}

/* ---------- schema safe columns ---------- */
$sub_user_col = db_has_col($conn, "submissions", "user_id") ? "user_id" : "student_id";
$has_score    = db_has_col($conn, "submissions", "score");
$has_status   = db_has_col($conn, "submissions", "status");
$has_verdict  = db_has_col($conn, "submissions", "verdict");
$has_message  = db_has_col($conn, "submissions", "message");
$has_runtime  = db_has_col($conn, "submissions", "runtime_ms");
$has_lang     = db_has_col($conn, "submissions", "language");
$has_code     = db_has_col($conn, "submissions", "answer_text");

/* ---------- insert (Running) ---------- */
$status = "Running";

$cols = [$sub_user_col, "problem_id"];
$types = "ii";
$vals  = [$user_id, $problem_id];

if ($has_lang) { $cols[]="language"; $types.="s"; $vals[]=$lang; }
if ($has_code) { $cols[]="answer_text"; $types.="s"; $vals[]=$code; }
if ($has_status){ $cols[]="status"; $types.="s"; $vals[]=$status; }

$place = implode(",", array_fill(0, count($cols), "?"));
$sqlIns = "INSERT INTO submissions (".implode(",", $cols).") VALUES ($place)";
$stIns = $conn->prepare($sqlIns);
if (!$stIns) {
  set_flash("err", "Failed to save submission.");
  redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);
}
$bind = [];
$bind[] = $types;
foreach ($vals as $k => $v) $bind[] = &$vals[$k];
call_user_func_array([$stIns, "bind_param"], $bind);

if (!$stIns->execute()) {
  set_flash("err", "Failed to save submission.");
  redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);
}
$submission_id = (int)$conn->insert_id;

/* ---------- run judge ---------- */
$t0 = microtime(true);
$result = local_judge($code, $lang, $cases, ["time_limit_ms" => 2000]);
$runtime_ms = (int)((microtime(true) - $t0) * 1000);

$verdict = (string)($result["verdict"] ?? "RE");

/* ---------- build CLEAN message (no JSON) ---------- */
$msg = "";
if ($verdict === "CE") {
  $msg = clean_compiler_log((string)($result["compile_log"] ?? ""));
} elseif ($verdict === "WA") {
  $ff = $result["first_fail"] ?? null;
  $caseNo = (int)($ff["case_no"] ?? 0);
  $msg = "Wrong Answer";
  if ($caseNo > 0) $msg .= " on case " . $caseNo;

  $exp = isset($ff["expected"]) ? trim_block((string)$ff["expected"], 400) : "";
  $got = isset($ff["got"]) ? trim_block((string)$ff["got"], 400) : "";
  if ($exp !== "" || $got !== "") {
    $msg .= "\n\nExpected:\n" . ($exp !== "" ? $exp : "(empty)");
    $msg .= "\n\nGot:\n" . ($got !== "" ? $got : "(empty)");
  }
} elseif ($verdict === "TLE") {
  $msg = "Time limit exceeded.";
} elseif ($verdict === "RE") {
  $msg = "Runtime error.";
  $stderr = (string)($result["first_fail"]["stderr"] ?? "");
  $stderr = trim_block($stderr, 700);
  if ($stderr !== "") $msg .= "\n\n" . $stderr;
} else {
  $msg = "Accepted.";
}

/* ---------- score ---------- */
// If you ALWAYS want 100 for AC, keep 100.
// If you want "problem points", use $points.
$score = ($verdict === "AC") ? $points : 0;

/* ---------- update row -> Checked ---------- */
$status = "Checked";

$set = [];
$uTypes = "";
$uVals  = [];

if ($has_score)   { $set[]="score=?";      $uTypes.="i"; $uVals[]=$score; }
if ($has_status)  { $set[]="status=?";     $uTypes.="s"; $uVals[]=$status; }
if ($has_verdict) { $set[]="verdict=?";    $uTypes.="s"; $uVals[]=$verdict; }
if ($has_message) { $set[]="message=?";    $uTypes.="s"; $uVals[]=$msg; }
if ($has_runtime) { $set[]="runtime_ms=?"; $uTypes.="i"; $uVals[]=$runtime_ms; }

if (!empty($set)) {
  $sqlUp = "UPDATE submissions SET ".implode(", ", $set)." WHERE id=? AND $sub_user_col=? LIMIT 1";
  $stUp = $conn->prepare($sqlUp);
  if ($stUp) {
    $uTypes2 = $uTypes . "ii";
    $uVals[] = $submission_id;
    $uVals[] = $user_id;

    $b = [];
    $b[] = $uTypes2;
    foreach ($uVals as $k => $v) $b[] = &$uVals[$k];
    call_user_func_array([$stUp, "bind_param"], $b);
    $stUp->execute();
  }
}

/* ---------- flash ---------- */
if ($verdict === "AC") set_flash("ok", "✅ Accepted!");
else if ($verdict === "WA") set_flash("err", "❌ Wrong Answer");
else if ($verdict === "CE") set_flash("err", "⚠️ Compilation Error");
else if ($verdict === "TLE") set_flash("err", "⏱️ Time Limit Exceeded");
else set_flash("err", "💥 Runtime Error");

redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);


