<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/ai_config.php";
require_once __DIR__ . "/../includes/ai_provider.php";
require_once __DIR__ . "/../includes/ai_meta_store.php"; // contains ai_cache_* + ai_sha256
require_once __DIR__ . "/../includes/ai_requests_logger.php";

// ✅ usage limit
require_once __DIR__ . "/../includes/ai_usage_limit.php";

// Always JSON, never HTML
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

function extract_keywords(string $title, string $statement): array {
  $all = strtolower(trim($title . " " . $statement));
  $bag = [
    "gcd","lcm","prime","factorial","fibonacci","palindrome","quadratic","equation",
    "velocity","displacement","distance","height","time","seconds","minutes","hours",
    "circle","area","perimeter","radius","diameter","pi","sqrt","root","power",
    "even","odd","sum","average","mean","maximum","minimum","series","triangle",
    "temperature","celsius","fahrenheit","convert","mod","remainder","percentage",
    "leap","year","interest","profit","loss"
  ];
  $k = [];
  foreach ($bag as $w) if (strpos($all, $w) !== false) $k[] = $w;
  $k = array_values(array_unique($k));
  return array_slice($k, 0, 8);
}

function ai_clean_hint_bullets(string $text, array $keywords, string $kwText): string {
  $text = trim((string)$text);
  $lines = preg_split("/\r?\n/", $text);
  $bullets = [];

  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === "") continue;
    if (stripos($ln, "<think>") !== false) continue;

    if (preg_match('/^[-•]\s+/', $ln)) {
      $bullets[] = preg_replace('/^[-•]\s+/', '- ', $ln);
    }
  }

  if (count($bullets) < 3) {
    $bullets = [
      "- Read the input carefully (how many values? what type?).",
      "- Identify the exact required output (format, spaces, newline).",
      "- Use the main idea from the statement: " . ($kwText !== "none" ? $kwText : "the given rule/formula") . ".",
      "- Handle edge cases (like 0, 1, negative, or equal values if relevant).",
      "- Print only the required answer; no extra text."
    ];
  }

  $bullets = array_values(array_filter(array_map("trim", $bullets)));
  $bullets = array_slice($bullets, 0, 8);
  while (count($bullets) < 5) $bullets[] = "- Re-check output formatting (spaces/newlines) before submitting.";

  return implode("\n", $bullets);
}

/* ---------- inputs ---------- */
$pid = (int)($_POST["problem_id"] ?? 0);
$user_id = (int)($_SESSION["user"]["id"] ?? 0);
$role = (string)($_SESSION["user"]["role"] ?? "student");

$usage_used  = 0;
$usage_limit = 0;

if ($pid <= 0) { echo json_encode(["ok"=>false,"error"=>"Invalid problem id"]); exit; }

/* ✅ DAILY LIMIT (students only) */
if ($role === "student") {
  $limit = defined("AI_LIMIT_HINT_DAILY") ? (int)AI_LIMIT_HINT_DAILY : 20;

  // this function exists in your ai_usage_limit.php
  [$allowed, $used, $lim] = ai_usage_allow_and_increment($user_id, "hint", $limit);

  $usage_used  = (int)$used;
  $usage_limit = (int)$lim;

  if (!$allowed) {
    echo json_encode([
      "ok" => false,
      "error" => "Daily AI limit reached for hints",
      "error_code" => "AI_LIMIT_REACHED",
      "detail" => "You used {$used}/{$lim} hints today. Try again tomorrow.",
      "usage_used" => $usage_used,
      "usage_limit" => $usage_limit
    ]);
    exit;
  }
}

/* ---------- load problem ---------- */
$has_out = db_has_col($conn, "problems", "sample_output");
$sql = "SELECT title, statement" . ($has_out ? ", sample_output" : "") . " FROM problems WHERE id=? LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param("i", $pid);
$st->execute();
$p = $st->get_result()->fetch_assoc();
if (!$p) { echo json_encode(["ok"=>false,"error"=>"Problem not found"]); exit; }

$title = (string)($p["title"] ?? "");
$statement = (string)($p["statement"] ?? "");
$sample_out = $has_out ? trim((string)($p["sample_output"] ?? "")) : "";

$keywords = extract_keywords($title, $statement);
$kwText = $keywords ? implode(", ", $keywords) : "none";

/* ---------- prompt ---------- */
$prompt =
"YOU ARE A COMPETITIVE PROGRAMMING TUTOR.\n".
"RULES:\n".
"- Do NOT provide full solution code.\n".
"- Do NOT provide full pseudocode.\n".
"- Give ONLY short hints.\n".
"- Output 5–8 bullet points. Each bullet must start with '- '.\n".
"- Do NOT include <think> or reasoning.\n".
"- At least 3 bullets MUST mention something specific from the problem.\n\n".
"TITLE: {$title}\n\n".
"KEYWORDS: {$kwText}\n\n".
"STATEMENT:\n{$statement}\n\n";

if ($sample_out !== "") {
  $prompt .= "SAMPLE OUTPUT (must match exactly):\n{$sample_out}\n\n";
}
$prompt .= "OUTPUT: Only bullet hints.";

$promptHash = ai_sha256($prompt);
$model_for_cache = defined("OLLAMA_MODEL_HINT") ? OLLAMA_MODEL_HINT : "gemma3:1b";

/* ---------- CACHE ---------- */
$cached = ai_cache_get("hint", $model_for_cache, $promptHash);
if ($cached) {
  $cachedText = (string)($cached["response_text"] ?? "");

  // ✅ simple log (doesn't break your schema)
  ai_request_log_simple($user_id, $role, $prompt, $model_for_cache, AI_TEMP_HINT);

  echo json_encode([
    "ok"=>true,
    "cached"=>true,
    "hint"=>$cachedText,
    "usage_used"=>$usage_used,
    "usage_limit"=>$usage_limit
  ]);
  exit;
}

$res = ai_infer_text("hint", $prompt, [
  "temperature" => AI_TEMP_HINT,
  "max_tokens"  => AI_MAX_TOKENS_HINT
]);

$model_used = (string)($res["model"] ?? $model_for_cache);

if (empty($res["ok"])) {
  ai_request_log_simple($user_id, $role, $prompt, $model_used, AI_TEMP_HINT);

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

/* ---------- CLEAN + SAVE CACHE ---------- */
$hint = ai_clean_hint_bullets((string)($res["text"] ?? ""), $keywords, $kwText);
ai_cache_put("hint", $model_used, $promptHash, $hint);

/* ---------- LOG ---------- */
ai_request_log_simple($user_id, $role, $prompt, $model_used, AI_TEMP_HINT);

echo json_encode([
  "ok"=>true,
  "cached"=>false,
  "hint"=>$hint,
  "usage_used"=>$usage_used,
  "usage_limit"=>$usage_limit
]);
exit;










