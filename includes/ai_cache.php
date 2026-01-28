<?php
require_once __DIR__ . "/db.php"; // provides $conn for main DB connection

function ai_cache_key(string $kind, string $model, string $prompt): array {
  $prompt_hash = sha1($prompt);
  $cache_key = sha1($kind . "|" . $model . "|" . $prompt_hash);
  return [$cache_key, $prompt_hash];
}

function ai_cache_get(mysqli $conn, string $cache_key): ?array {
  // Respect expiration if used
  $sql = "SELECT response, latency_ms
          FROM uiu_brainnext_meta.ai_cache
          WHERE cache_key = ?
            AND (expires_at IS NULL OR expires_at > NOW())
          LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return null;

  $st->bind_param("s", $cache_key);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();

  return $row ? [
    "text" => (string)$row["response"],
    "latency_ms" => (int)($row["latency_ms"] ?? 0),
  ] : null;
}

function ai_cache_put(mysqli $conn, string $cache_key, string $kind, string $model, string $prompt_hash, string $response, int $latency_ms = 0, ?int $ttl_seconds = 86400): void {
  // ttl default: 1 day. Set null to never expire.
  $expires_at = null;
  if ($ttl_seconds !== null) {
    $expires_at = date("Y-m-d H:i:s", time() + $ttl_seconds);
  }

  $sql = "INSERT INTO uiu_brainnext_meta.ai_cache (cache_key, kind, model, prompt_hash, response, latency_ms, expires_at)
          VALUES (?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE
            response=VALUES(response),
            latency_ms=VALUES(latency_ms),
            expires_at=VALUES(expires_at)";
  $st = $conn->prepare($sql);
  if (!$st) return;

  $st->bind_param("sssssis", $cache_key, $kind, $model, $prompt_hash, $response, $latency_ms, $expires_at);
  $st->execute();
  $st->close();
}
