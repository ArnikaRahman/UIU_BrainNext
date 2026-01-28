<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/ai_config.php";
require_once __DIR__ . "/../includes/ai_provider.php";
require_once __DIR__ . "/../includes/ai_meta_store.php";        // ai_cache_get/put, ai_sha256
require_once __DIR__ . "/../includes/ai_usage_limit.php";       // daily limit
require_once __DIR__ . "/../includes/ai_requests_logger.php";   // ai_request_log_simple()

// Always JSON
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
function strip_think_and_noise(string $t): string {
  $t = (string)$t;
  $t = preg_replace('/<think>.*?<\/think>/is', '', $t);
  $t = str_replace(["<think>", "</think>"], "", $t);
  return trim($t);
}

function extract_keywords_fb(string $title, string $statement, string $judgeMsg): array {
  $all = strtolower(trim($title . " " . $statement . " " . $judgeMsg));

  $bag = [
    "gcd","lcm","prime","factorial","fibonacci","palindrome","quadratic","equation",
    "velocity","displacement","distance","height","time","seconds","minutes","hours",
    "circle","area","perimeter","radius","diameter","sqrt","root","power",
    "even","odd","sum","average","mean","maximum","minimum","series","triangle",
    "temperature","celsius","fahrenheit","convert","mod","remainder","percentage",
    "leap","year","interest","profit","loss",
    "expected","before","token","semicolon","missing","include","stdio","iostream",
    "undeclared","not declared","undefined reference","linker","runtime","segmentation",
    "time limit","wrong answer","compile","compilation","syntax","bracket","brace","quote"
  ];

  $k = [];
  foreach ($bag as $w) if (strpos($all, $w) !== false) $k[] = $w;
  $k = array_values(array_unique($k));
  return array_slice($k, 0, 10);
}

function normalize_sections_to_bullets(string $text): string {
  $text = strip_think_and_noise($text);
  $text = preg_replace('/```.*?```/s', '', $text); // remove code blocks

  $need = ["Likely issue:", "Why:", "How to fix:", "Test cases:"];
  foreach ($need as $h) {
    if (stripos($text, $h) === false) return "";
  }

  $parts = preg_split('/\b(Likely issue:|Why:|How to fix:|Test cases:)\b/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
  if (!$parts || count($parts) < 2) return "";

  $map = [
    "likely issue:" => [],
    "why:" => [],
    "how to fix:" => [],
    "test cases:" => [],
  ];

  $cur = null;
  for ($i = 0; $i < count($parts); $i++) {
    $p = trim($parts[$i]);
    if ($p === "") continue;

    $key = strtolower($p);
    if (isset($map[$key])) { $cur = $key; continue; }

    if ($cur) {
      $lines = preg_split("/\r?\n/", $p);
      foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === "") continue;

        if (preg_match('/^(okay|first|next|so|wait|let me|i think|we need)/i', $ln)) continue;

        if (preg_match('/^[-•]\s+/', $ln)) $ln = preg_replace('/^[-•]\s+/', '- ', $ln);
        else $ln = "- " . $ln;

        if (strlen($ln) > 260) $ln = substr($ln, 0, 260) . "...";
        $map[$cur][] = $ln;
      }
    }
  }

  if (count($map["likely issue:"]) < 1) $map["likely issue:"][] = "- The verdict suggests a syntax/output/runtime issue; locate the first failing point.";
  if (count($map["why:"]) < 1) $map["why:"][] = "- The judge/compiler message indicates the earliest root cause; later errors often cascade.";
  if (count($map["how to fix:"]) < 2) {
    $map["how to fix:"][] = "- Fix the FIRST compiler/judge error shown (line/column) before anything else.";
    $map["how to fix:"][] = "- Re-check required output formatting (spaces/newlines) and remove extra prints.";
  }
  if (count($map["test cases:"]) < 2) {
    $map["test cases:"][] = "- Run the sample input/output and compare character-by-character.";
    $map["test cases:"][] = "- Try smallest/edge values mentioned by the constraints (if any).";
  }

  foreach ($map as $k => $arr) $map[$k] = array_slice($arr, 0, 6);

  $out  = "Likely issue:\n" . implode("\n", $map["likely issue:"]) . "\n\n";
  $out .= "Why:\n" . implode("\n", $map["why:"]) . "\n\n";
  $out .= "How to fix:\n" . implode("\n", $map["how to fix:"]) . "\n\n";
  $out .= "Test cases:\n" . implode("\n", $map["test cases:"]) . "\n";

  return trim($out);
}

/* ---------------- inputs ---------------- */
$sid = (int)($_POST["submission_id"] ?? 0);
$user_id = (int)($_SESSION["user"]["id"] ?? 0);
$role = (string)($_SESSION["user"]["role"] ?? "student");

$usage_used  = 0;
$usage_limit = 0;

if ($sid <= 0) { echo json_encode(["ok"=>false,"error"=>"Invalid submission id"]); exit; }

/* ✅ DAILY LIMIT (students only) */
if ($role === "student") {
  $limit = defined("AI_LIMIT_FEEDBACK_DAILY") ? (int)AI_LIMIT_FEEDBACK_DAILY : 10;
  [$allowed, $used, $lim] = ai_usage_allow_and_increment($user_id, "feedback", $limit);

  $usage_used  = (int)$used;
  $usage_limit = (int)$lim;

  if (!$allowed) {
    echo json_encode([
      "ok" => false,
      "error" => "Daily AI limit reached for feedback",
      "error_code" => "AI_LIMIT_REACHED",
      "detail" => "You used {$used}/{$lim} feedback requests today. Try again tomorrow.",
      "usage_used" => $usage_used,
      "usage_limit" => $usage_limit
    ]);
    exit;
  }
}

/* ---------------- load submission + problem ---------------- */
$st = $conn->prepare("
  SELECT s.id, s.problem_id, s.answer_text, s.language, s.status, s.verdict, s.message,
         p.title, p.statement
  FROM submissions s
  JOIN problems p ON p.id = s.problem_id
  WHERE s.id=? LIMIT 1
");
$st->bind_param("i", $sid);
$st->execute();
$row = $st->get_result()->fetch_assoc();

if (!$row) { echo json_encode(["ok"=>false,"error"=>"Submission not found"]); exit; }

$title     = (string)($row["title"] ?? "");
$statement = (string)($row["statement"] ?? "");
$verdict   = (string)($row["verdict"] ?? $row["status"] ?? "Unknown");
$msg       = (string)($row["message"] ?? "");

$keywords = extract_keywords_fb($title, $statement, $msg);
$kwText = $keywords ? implode(", ", $keywords) : "none";

$codeFull = (string)($row["answer_text"] ?? "");
$codeExcerpt = trim(substr($codeFull, 0, 450));
if ($codeExcerpt === "") $codeExcerpt = "(empty)";

/* ---------------- prompt ---------------- */
$prompt =
"YOU ARE A PROGRAMMING JUDGE FEEDBACK BOT.\n".
"RULES:\n".
"- Do NOT output full corrected code.\n".
"- Do NOT provide full solution.\n".
"- Do NOT include <think> or narration.\n".
"- Output ONLY the sections below.\n".
"- Each section must contain bullet points starting with '- '.\n".
"- Mention at least TWO details from the judge message or code excerpt.\n".
"- Keep bullets short and actionable.\n\n".
"SECTIONS (must match exactly):\n".
"Likely issue:\n".
"Why:\n".
"How to fix:\n".
"Test cases:\n\n".
"KEYWORDS: ".$kwText."\n\n".
"PROBLEM: ".$title."\n\n".
"VERDICT: ".$verdict."\n".
"JUDGE MESSAGE:\n".$msg."\n\n".
"LANG: ".(string)($row["language"] ?? "")."\n\n".
"CODE EXCERPT (NOT full code):\n".$codeExcerpt."\n";

$promptHash = ai_sha256($prompt);
$model_for_cache = defined("OLLAMA_MODEL_FEEDBACK") ? OLLAMA_MODEL_FEEDBACK : "gemma3:1b";

/* ---------------- CACHE ---------------- */
$cached = ai_cache_get("feedback", $model_for_cache, $promptHash);
if ($cached) {
  $cachedText = (string)($cached["response_text"] ?? "");

  // ✅ simplified logging
  ai_request_log_simple($user_id, $role, $prompt, $model_for_cache, AI_TEMP_FEEDBACK);

  echo json_encode([
    "ok"=>true,
    "cached"=>true,
    "feedback"=>$cachedText,
    "usage_used"=>$usage_used,
    "usage_limit"=>$usage_limit
  ]);
  exit;
}

/* ---------------- AI CALL ---------------- */
$res = ai_infer_text("feedback", $prompt, [
  "temperature" => AI_TEMP_FEEDBACK,
  "max_tokens"  => AI_MAX_TOKENS_FEEDBACK
]);

$model_used = (string)($res["model"] ?? $model_for_cache);

if (empty($res["ok"])) {
  // ✅ simplified logging (error case too)
  ai_request_log_simple($user_id, $role, $prompt, $model_used, AI_TEMP_FEEDBACK);

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

/* ---------------- CLEAN + SAVE CACHE ---------------- */
$raw = (string)($res["text"] ?? "");
$clean = normalize_sections_to_bullets($raw);

if ($clean === "") {
  $clean =
"Likely issue:\n".
"- The verdict indicates a compile/runtime/output mismatch problem.\n".
"- The judge message likely points to the first failing syntax/output detail.\n\n".
"Why:\n".
"- The first compiler/judge error is the root cause; later errors can be a chain reaction.\n\n".
"How to fix:\n".
"- Fix the FIRST reported error in the judge message (line/column if shown).\n".
"- Check missing semicolons/brackets/quotes and correct headers for the selected language.\n".
"- Ensure output matches exactly (spaces/newlines) and remove extra prints.\n\n".
"Test cases:\n".
"- Run the sample test exactly.\n".
"- Try edge/minimal values mentioned by the statement (if applicable).\n";
}

ai_cache_put("feedback", $model_used, $promptHash, $clean);

/* ---------------- LOG (simple schema) ---------------- */
ai_request_log_simple($user_id, $role, $prompt, $model_used, AI_TEMP_FEEDBACK);

echo json_encode([
  "ok"=>true,
  "cached"=>false,
  "feedback"=>$clean,
  "usage_used"=>$usage_used,
  "usage_limit"=>$usage_limit
]);
exit;


















