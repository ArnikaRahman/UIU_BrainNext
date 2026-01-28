<?php
// includes/ai_usage_limit.php
require_once __DIR__ . "/ai_meta_store.php"; // for ai_meta_conn()

/**
 * Atomically increments today's usage if under limit.
 * Returns array: [allowed(bool), used(int), limit(int)]
 *
 * Safe: if meta DB / table missing -> allow (doesn't block)
 */
function ai_usage_allow_and_increment(int $user_id, string $feature, int $limit): array {
  if ($user_id <= 0) return [false, 0, $limit];
  if ($limit <= 0) return [false, 0, $limit];

  $mc = ai_meta_conn();
  if (!$mc) return [true, 0, $limit]; // meta db missing -> don't block

  // table exists?
  $chk = @$mc->query("SHOW TABLES LIKE 'ai_usage_daily'");
  if (!$chk || $chk->num_rows === 0) return [true, 0, $limit];

  $feature = substr($feature, 0, 50);

  // Atomic upsert:
  // - Insert 1 if new
  // - Else increment only if used_count < limit
  $st = $mc->prepare("
    INSERT INTO ai_usage_daily (user_id, feature, ymd, used_count)
    VALUES (?, ?, CURDATE(), 1)
    ON DUPLICATE KEY UPDATE
      used_count = IF(used_count < ?, used_count + 1, used_count),
      updated_at = CURRENT_TIMESTAMP
  ");
  if (!$st) return [true, 0, $limit];

  $st->bind_param("isi", $user_id, $feature, $limit);
  @$st->execute();

  // affected_rows meanings:
  // 1 = inserted (allowed)
  // 2 = updated changed (allowed)
  // 0 = updated but no change (limit reached)
  $ar = (int)$mc->affected_rows;

  $used = 0;
  $st2 = $mc->prepare("SELECT used_count FROM ai_usage_daily WHERE user_id=? AND feature=? AND ymd=CURDATE() LIMIT 1");
  if ($st2) {
    $st2->bind_param("is", $user_id, $feature);
    @$st2->execute();
    $r = $st2->get_result()->fetch_assoc();
    $used = (int)($r["used_count"] ?? 0);
  }

  if ($ar === 0) return [false, $used, $limit];
  return [true, $used, $limit];
}
