<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/ai_config.php";
require_once __DIR__ . "/../includes/ai_provider.php";
require_once __DIR__ . "/../includes/ai_meta_store.php";        // ai_cache_get/put, ai_sha256, ai_token_estimate (optional)
require_once __DIR__ . "/../includes/ai_usage_limit.php";       // daily limit
require_once __DIR__ . "/../includes/ai_requests_logger.php";   // ai_request_log_simple()

header("Content-Type: application/json; charset=utf-8");
ini_set("display_errors", "0");
error_reporting(E_ALL);

register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && !headers_sent()) {
    http_response_code(500);
    echo json_encode(["ok"=>false, "error"=>"Server error", "detail"=>$e["message"]]);
  }
});

/* ---------------- helpers ---------------- */
function strip_noise_cc(string $t): string {
  $t = (string)$t;
  $t = preg_replace('/<think>.*?<\/think>/is', '', $t);
  $t = str_replace(["<think>", "</think>"], "", $t);
  $t = preg_replace('/```[\s\S]*?```/s', '', $t);
  return trim($t);
}

function normalize_codecheck(string $text): array {
  $t = strip_noise_cc($text);

  $code = null;
  $logic = null;
  if (preg_match('/Code\s*correct\s*:\s*(Yes|No)/i', $t, $m)) $code = ucfirst(strtolower($m[1]));
  if (preg_match('/Logic\s*correct\s*:\s*(Yes|No)/i', $t, $m)) $logic = ucfirst(strtolower($m[1]));

  $bullets = [];
  if (preg_match('/Explanation\s*of\s*the\s*error\s*:\s*(.*)$/is', $t, $m)) {
    $tail = trim($m[1]);
    $lines = preg_split("/\r?\n/", $tail);
    foreach ($lines as $ln) {
      $ln = trim($ln);
      if ($ln === "") continue;
      $ln = preg_replace('/^\s*(?:[-•]|\d+\.)\s*/', '', $ln);
      if ($ln === "") continue;
      $bullets[] = $ln;
      if (count($bullets) >= 8) break;
    }
  }

  if (!$code) $code = "No";
  if (!$logic) $logic = "No";
  if (!$bullets) $bullets = ["Could not parse a clean explanation. Please click again."];

  $pretty =
    "Code correct: {$code}\n".
    "Logic correct: {$logic}\n".
    "Explanation of the error:\n- " . implode("\n- ", $bullets);

  return [
    "code_correct" => $code,
    "logic_correct" => $logic,
    "bullets" => $bullets,
    "pretty" => $pretty,
    "raw" => $t
  ];
}

/* ---------------- inputs ---------------- */
$sid = (int)($_POST["submission_id"] ?? 0);
$pid = (int)($_POST["problem_id"] ?? 0);
$draft_lang = (string)($_POST["language"] ?? "");
$draft_code = (string)($_POST["code"] ?? "");
$user_id = (int)($_SESSION["user"]["id"] ?? 0);
$role = (string)($_SESSION["user"]["role"] ?? "student");

$usage_used  = 0;
$usage_limit = 0;

// Supports two modes:
// 1) Post-submit: submission_id (existing)
// 2) Pre-submit draft: problem_id + language + code

$title = "";
$statement = "";
$solution = "";
$lang = "";

$resource_type = "submission";
$resource_id = $sid;

if ($sid > 0) {
  $st = $conn->prepare("
    SELECT s.id, s.problem_id, s.answer_text, s.language,
           p.title, p.statement
    FROM submissions s
    JOIN problems p ON p.id = s.problem_id
    WHERE s.id=?
    LIMIT 1
  ");
  $st->bind_param("i", $sid);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();

  if (!$row) {
    echo json_encode(["ok"=>false, "error"=>"Submission not found"]);
    exit;
  }

  $title = (string)($row["title"] ?? "");
  $statement = (string)($row["statement"] ?? "");
  $solution = (string)($row["answer_text"] ?? "");
  $lang = (string)($row["language"] ?? "");
} else {
  if ($pid <= 0) {
    echo json_encode(["ok"=>false, "error"=>"Missing problem id"]);
    exit;
  }

  $draft_lang = strtolower(trim($draft_lang));
  if (!in_array($draft_lang, ["c", "cpp"], true)) {
    echo json_encode(["ok"=>false, "error"=>"Only C / C++ are allowed"]);
    exit;
  }

  $draft_code = trim((string)$draft_code);
  if ($draft_code === "") {
    echo json_encode(["ok"=>false, "error"=>"No code provided"]);
    exit;
  }

  $stp = $conn->prepare("SELECT title, statement FROM problems WHERE id=? LIMIT 1");
  $stp->bind_param("i", $pid);
  $stp->execute();
  $pr = $stp->get_result()->fetch_assoc();
  if (!$pr) {
    echo json_encode(["ok"=>false, "error"=>"Problem not found"]);
    exit;
  }

  $title = (string)($pr["title"] ?? "");
  $statement = (string)($pr["statement"] ?? "");
  $solution = (string)$draft_code;
  $lang = (string)$draft_lang;

  $resource_type = "draft";
  $resource_id = $pid;
}

$solution = substr($solution, 0, defined("AI_MAX_CODE_CHARS") ? AI_MAX_CODE_CHARS : 8000);

$langUpper = strtoupper(trim($lang));
if ($langUpper === "CPP") $langUpper = "C++";
if ($langUpper === "C") $langUpper = "C";
if ($langUpper === "") $langUpper = "UNKNOWN";

/* ✅ DAILY LIMIT (students only) */
$TEMP_CODECHECK = defined("AI_TEMP_CODECHECK") ? AI_TEMP_CODECHECK : 0.0;

if ($role === "student") {
  $limit = defined("AI_LIMIT_CODECHECK_DAILY") ? (int)AI_LIMIT_CODECHECK_DAILY : 10;
  [$allowed, $used, $lim] = ai_usage_allow_and_increment($user_id, "codecheck", $limit);

  $usage_used  = (int)$used;
  $usage_limit = (int)$lim;

  if (!$allowed) {
    echo json_encode([
      "ok" => false,
      "error" => "Daily AI limit reached for code check",
      "error_code" => "AI_LIMIT_REACHED",
      "detail" => "You used {$used}/{$lim} codecheck requests today. Try again tomorrow.",
      "usage_used" => $usage_used,
      "usage_limit" => $usage_limit
    ]);
    exit;
  }
}

/* ---------- Prompt (strong language enforcement) ---------- */
$prompt =
"You are a strict programming judge.\n".
"Your job: verify the student's submission for the given problem.\n\n".
"Selected language = {$langUpper}\n\n".
"ABSOLUTE RULES:\n".
"1) Do NOT mention any other programming language.\n".
"2) Do NOT mention libraries/functions from other languages.\n".
"   - If Selected language is C, do NOT mention: cout, cin, iostream, using namespace std.\n".
"   - If Selected language is C++, do NOT mention Java main.\n".
"3) Do NOT output code. Do NOT show corrected code.\n".
"4) Output ONLY this exact format:\n".
"Code correct: Yes/No\n".
"Logic correct: Yes/No\n".
"Explanation of the error:\n".
"- ...\n\n".
"PROBLEM:\n".$title."\n".$statement."\n\n".
"STUDENT SUBMISSION:\n".$solution."\n";

/* ---------- Cache key ---------- */
$promptHash = ai_sha256($prompt);
$model_for_cache = defined("OLLAMA_MODEL_CODECHECK")
  ? OLLAMA_MODEL_CODECHECK
  : (defined("OLLAMA_MODEL_FEEDBACK") ? OLLAMA_MODEL_FEEDBACK : "gemma3:4b-it-q4_K_M");

/* ---------- Cache read ---------- */
$cached = ai_cache_get("codecheck", $model_for_cache, $promptHash);
if ($cached) {
  $cachedText = (string)($cached["response_text"] ?? "");
  $parsed = normalize_codecheck($cachedText);

  // ✅ simple log (cached also logs usage)
  ai_request_log_simple($user_id, $role, $prompt, $model_for_cache, $TEMP_CODECHECK);

  echo json_encode([
    "ok"=>true,
    "cached"=>true,
    "result"=>$parsed,
    "usage_used"=>$usage_used,
    "usage_limit"=>$usage_limit
  ]);
  exit;
}

/* ---------- AI call ---------- */
$res = ai_infer_text("codecheck", $prompt, [
  "temperature" => $TEMP_CODECHECK,
  "max_tokens"  => defined("AI_MAX_TOKENS_CODECHECK") ? AI_MAX_TOKENS_CODECHECK : 220
]);

$model_used = (string)($res["model"] ?? $model_for_cache);

if (empty($res["ok"])) {
  // ✅ simple log (error case too)
  ai_request_log_simple($user_id, $role, $prompt, $model_used, $TEMP_CODECHECK);

  $code = (string)($res["error_code"] ?? "");
  if ($code === "OLLAMA_OFFLINE") {
    echo json_encode([
      "ok"=>false,
      "error"=>"AI is offline",
      "error_code"=>"OLLAMA_OFFLINE",
      "detail"=>"Ollama server is not reachable. Start Ollama (ollama serve) and refresh.",
      "usage_used"=>$usage_used,
      "usage_limit"=>$usage_limit
    ]);
  } else {
    echo json_encode([
      "ok"=>false,
      "error"=>"AI error",
      "error_code"=>$code ?: "AI_ERROR",
      "detail"=>$res["error"] ?? "Unknown",
      "usage_used"=>$usage_used,
      "usage_limit"=>$usage_limit
    ]);
  }
  exit;
}

/* ---------- Post-processing + safety ---------- */
$raw = (string)($res["text"] ?? "");
$raw_lc = strtolower($raw);

/* 1) Block real code output */
if (preg_match(
  '/```|^\s*#include\s*<.*?>|^\s*(int|void)\s+main\s*\(|^\s*using\s+namespace\s+std|^\s*public\s+static\s+void\s+main/smi',
  $raw
)) {
  $raw =
    "Code correct: No\n".
    "Logic correct: No\n".
    "Explanation of the error:\n".
    "- The assistant output included source code instead of analysis.";
}

/* 2) Hard language mismatch filter */
if ($langUpper === "C" && preg_match('/\bcout\b|\bcin\b|iostream|namespace\s+std/', $raw_lc)) {
  $raw =
    "Code correct: No\n".
    "Logic correct: No\n".
    "Explanation of the error:\n".
    "- The feedback incorrectly referenced C++ (cout/cin/iostream). Please re-check using C only.";
}

if ($langUpper === "C++" && preg_match('/public\s+static\s+void\s+main/', $raw_lc)) {
  $raw =
    "Code correct: No\n".
    "Logic correct: No\n".
    "Explanation of the error:\n".
    "- The feedback incorrectly referenced Java. Please re-check using C++ only.";
}

/* ---------- Normalize to strict format ---------- */
$parsed = normalize_codecheck($raw);
$storeText = $parsed["pretty"];

/* ---------- Save cache + log ---------- */
ai_cache_put("codecheck", $model_used, $promptHash, $storeText);

// ✅ simple log (success)
ai_request_log_simple($user_id, $role, $prompt, $model_used, $TEMP_CODECHECK);

echo json_encode([
  "ok"=>true,
  "cached"=>false,
  "result"=>$parsed,
  "usage_used"=>$usage_used,
  "usage_limit"=>$usage_limit
]);
exit;





