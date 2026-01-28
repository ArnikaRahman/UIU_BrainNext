<?php
// includes/ai_requests_logger.php
require_once __DIR__ . "/ai_meta_store.php";

/**
 * Insert into uiu_brainnext_meta.ai_requests
 * Matches CURRENT table columns:
 * (id, user_id, role, prompt, model, temperature, created_at)
 */
function ai_request_log_simple(
  int $user_id,
  string $role,
  string $prompt,
  string $model,
  ?float $temperature
): void {

  $mc = ai_meta_conn();
  if (!$mc) return;

  $chk = $mc->query("SHOW TABLES LIKE 'ai_requests'");
  if (!$chk || $chk->num_rows === 0) return;

  $uid = ($user_id > 0) ? $user_id : null;
  $role = substr((string)$role, 0, 20);
  $model = substr((string)$model, 0, 80);

  // prompt is MEDIUMTEXT, so no need to cut, but keep it reasonable anyway
  $prompt = (string)$prompt;

  $temp = ($temperature !== null) ? (float)$temperature : null;

  $st = $mc->prepare("
    INSERT INTO ai_requests (user_id, role, prompt, model, temperature)
    VALUES (?, ?, ?, ?, ?)
  ");
  if (!$st) return;

  $st->bind_param("isssd", $uid, $role, $prompt, $model, $temp);
  @$st->execute();
}

