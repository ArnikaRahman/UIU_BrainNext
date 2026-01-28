<?php
/**
 * Meta DB utilities + AI cache + audit logger.
 * Uses includes/db_meta.php if present (expects $conn_meta).
 * Falls back to global $conn otherwise.
 *
 * Required tables (meta DB recommended):
 * - ai_cache
 * - ai_audit_logs
 */

function ai_meta_conn(): ?mysqli {
  $metaFile = __DIR__ . "/db_meta.php";
  if (file_exists($metaFile)) {
    require_once $metaFile;
    // Accept either $conn_meta (preferred) or $meta_conn (legacy)
    if (isset($conn_meta) && $conn_meta instanceof mysqli) return $conn_meta;
    if (isset($meta_conn) && $meta_conn instanceof mysqli) return $meta_conn;
  }

  global $conn;
  if (isset($conn) && $conn instanceof mysqli) return $conn;

  return null;
}

/* ---------- hashing ---------- */
function ai_sha256(string $s): string {
  return hash("sha256", $s);
}

/* ---------- request info ---------- */
function ai_get_client_ip(): string {
  $ip = (string)($_SERVER["REMOTE_ADDR"] ?? "");
  return substr($ip, 0, 64);
}

function ai_get_user_agent(int $maxLen = 255): string {
  $ua = (string)($_SERVER["HTTP_USER_AGENT"] ?? "");
  return substr($ua, 0, $maxLen);
}

/** rough token estimate (good enough for logging) */
function ai_token_estimate(string $text): int {
  $n = strlen((string)$text);
  if ($n <= 0) return 0;
  return (int)ceil($n / 4);
}

/* ---------- cache ---------- */
function ai_cache_get(string $kind, string $model, string $promptHash): ?array {
  $mc = ai_meta_conn();
  if (!$mc) return null;

  $chk = $mc->query("SHOW TABLES LIKE 'ai_cache'");
  if (!$chk || $chk->num_rows === 0) return null;

  $st = $mc->prepare("
    SELECT response_text, response_sha256
    FROM ai_cache
    WHERE kind=? AND model=? AND prompt_sha256=?
    LIMIT 1
  ");
  if (!$st) return null;

  $st->bind_param("sss", $kind, $model, $promptHash);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();

  return $row ?: null;
}

function ai_cache_put(string $kind, string $model, string $promptHash, string $responseText): string {
  $mc = ai_meta_conn();
  $respHash = ai_sha256($responseText);
  if (!$mc) return $respHash;

  $chk = $mc->query("SHOW TABLES LIKE 'ai_cache'");
  if (!$chk || $chk->num_rows === 0) return $respHash;

  $st = $mc->prepare("
    INSERT INTO ai_cache (kind, model, prompt_sha256, response_sha256, response_text)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      response_sha256=VALUES(response_sha256),
      response_text=VALUES(response_text),
      created_at=CURRENT_TIMESTAMP
  ");
  if ($st) {
    $st->bind_param("sssss", $kind, $model, $promptHash, $respHash, $responseText);
    $st->execute();
  }

  return $respHash;
}

/* ---------- audit trail ---------- */
/**
 * Writes an audit row if ai_audit_logs exists.
 * Safe: if table doesn't exist, does nothing.
 *
 * NOTE: $ip and $user_agent are optional so wrapper can pass them OR we auto-detect.
 */
function ai_audit_log(
  int $user_id,
  string $role,
  string $action,
  string $model,
  string $target_type,
  int $target_id,
  string $prompt_sha256,
  string $title = "",
  string $status = "ok",
  int $latency_ms = 0,
  ?string $response_sha256 = null,
  string $response_preview = "",
  ?int $token_est = null,
  string $error_text = "",
  ?string $ip = null,
  ?string $user_agent = null
): void {
  $mc = ai_meta_conn();
  if (!$mc) return;

  $chk = $mc->query("SHOW TABLES LIKE 'ai_audit_logs'");
  if (!$chk || $chk->num_rows === 0) return;

  if ($ip === null) $ip = ai_get_client_ip();
  if ($user_agent === null) $user_agent = ai_get_user_agent(255);

  $title = substr((string)$title, 0, 255);
  $response_preview = substr((string)$response_preview, 0, 255);

  if ($token_est === null) $token_est = 0;
  if (strlen($error_text) > 5000) $error_text = substr($error_text, 0, 5000) . "...";

  // IMPORTANT: do NOT bind NULL ints -> use 0
  $uid = ($user_id > 0) ? $user_id : 0;
  $tid = ($target_id > 0) ? $target_id : 0;

  $st = $mc->prepare("
    INSERT INTO ai_audit_logs
      (user_id, role, action, model,
       target_type, target_id, title,
       status, latency_ms,
       prompt_sha256, response_sha256,
       response_preview, token_est,
       ip, user_agent, error_text)
    VALUES
      (?, ?, ?, ?,
       ?, ?, ?,
       ?, ?,
       ?, ?,
       ?, ?,
       ?, ?, ?)
  ");
  if (!$st) return;

  // types = 16 params:
  // i s s s  s i s  s i  s s  s i  s s s
  $st->bind_param(
    "issssississsisss",
    $uid, $role, $action, $model,
    $target_type, $tid, $title,
    $status, $latency_ms,
    $prompt_sha256, $response_sha256,
    $response_preview, $token_est,
    $ip, $user_agent, $error_text
  );

  $st->execute();
}

/* ---------- compatibility alias ---------- */
if (!function_exists("ai_log_request")) {
  function ai_log_request(
    int $user_id,
    string $role,
    string $action,
    string $model,
    string $target_type,
    int $target_id,
    string $prompt_sha256,
    string $title = "",
    string $status = "ok",
    int $latency_ms = 0,
    ?string $response_sha256 = null,
    string $response_preview = "",
    ?int $token_est = null,
    string $error_text = ""
  ): void {
    ai_audit_log(
      $user_id, $role, $action, $model,
      $target_type, $target_id, $prompt_sha256,
      $title, $status, $latency_ms,
      $response_sha256, $response_preview,
      $token_est, $error_text
    );
  }
}

