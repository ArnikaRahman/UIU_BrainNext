<?php
require_once __DIR__ . "/db_meta.php";

function client_ip(): string {
  $ip = $_SERVER["REMOTE_ADDR"] ?? "";

  // ✅ Convert localhost IPv6 to IPv4 for cleaner logs
  if ($ip === "::1") return "127.0.0.1";

  // ✅ If proxy headers exist (optional), you can uncomment below:
  // if (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
  //   $parts = explode(",", $_SERVER["HTTP_X_FORWARDED_FOR"]);
  //   $first = trim($parts[0] ?? "");
  //   if ($first !== "") $ip = $first;
  // }

  return substr($ip, 0, 45);
}

function client_ua(): string {
  $ua = $_SERVER["HTTP_USER_AGENT"] ?? "";
  return substr($ua, 0, 255);
}

/**
 * Log login attempt (success/fail)
 * NOTE: column name is "email" in meta DB, but you can store username there too.
 */
function log_login_attempt(?int $user_id, ?string $emailOrUsername, bool $success): void {
  global $conn_meta;
  if (!$conn_meta) return;

  $ip = client_ip();
  $ua = client_ua();
  $succ = $success ? 1 : 0;
  $who = (string)($emailOrUsername ?? "");

  // ✅ handle NULL user_id safely (user not found)
  if ($user_id === null) {
    $st = $conn_meta->prepare("
      INSERT INTO login_logs(user_id, email, success, ip, user_agent)
      VALUES(NULL, ?, ?, ?, ?)
    ");
    if (!$st) return;

    $st->bind_param("siss", $who, $succ, $ip, $ua);
    $st->execute();
    return;
  }

  $uid = (int)$user_id;

  $st = $conn_meta->prepare("
    INSERT INTO login_logs(user_id, email, success, ip, user_agent)
    VALUES(?, ?, ?, ?, ?)
  ");
  if (!$st) return;

  $st->bind_param("isiss", $uid, $who, $succ, $ip, $ua);
  $st->execute();
}

/**
 * Audit trail (admin/teacher actions)
 */
function audit_log(
  int $actor_user_id,
  string $actor_role,
  string $action,
  string $entity_table,
  int $entity_id,
  array $details = []
): void {
  global $conn_meta;
  if (!$conn_meta) return;

  $ip = client_ip();
  $ua = client_ua();

  $json = null;
  if (!empty($details)) {
    $tmp = json_encode($details, JSON_UNESCAPED_UNICODE);
    if ($tmp !== false) $json = $tmp;
  }

  // ✅ handle NULL details_json safely
  if ($json === null) {
    $st = $conn_meta->prepare("
      INSERT INTO audit_logs(actor_user_id, actor_role, action, entity_table, entity_id, details_json, ip, user_agent)
      VALUES(?, ?, ?, ?, ?, NULL, ?, ?)
    ");
    if (!$st) return;

    $st->bind_param("isssiss", $actor_user_id, $actor_role, $action, $entity_table, $entity_id, $ip, $ua);
    $st->execute();
    return;
  }

  $st = $conn_meta->prepare("
    INSERT INTO audit_logs(actor_user_id, actor_role, action, entity_table, entity_id, details_json, ip, user_agent)
    VALUES(?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$st) return;

  $st->bind_param("isssisss", $actor_user_id, $actor_role, $action, $entity_table, $entity_id, $json, $ip, $ua);
  $st->execute();
}



