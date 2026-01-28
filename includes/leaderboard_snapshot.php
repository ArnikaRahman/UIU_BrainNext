<?php
require_once __DIR__ . "/db_meta.php";
require_once __DIR__ . "/db.php"; // main DB ($conn)

/*
  Rebuild snapshot for a single test_id.
  Rules:
  - Only status='Checked' or 'Accepted' count
  - Score DESC rank
  - penalty optional (0 for now)
*/
function rebuild_leaderboard_snapshot(int $test_id): bool {
  global $conn, $conn_meta;

  // Step 1: load checked scores from main DB
  // Adjust columns if yours differs
  $rows = [];
  $st = $conn->prepare("
    SELECT user_id, score
    FROM test_submissions
    WHERE test_id = ?
      AND (status = 'Checked' OR status = 'Accepted')
    ORDER BY score DESC, id ASC
  ");
  if (!$st) return false;
  $st->bind_param("i", $test_id);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) $rows[] = $r;

  // Step 2: Insert snapshot rows with same generated_at
  $generated_at = date("Y-m-d H:i:s");

  $ins = $conn_meta->prepare("
    INSERT INTO leaderboard_snapshots(test_id, generated_at, user_id, score, penalty, rank_no)
    VALUES(?, ?, ?, ?, 0, ?)
  ");
  if (!$ins) return false;

  $rank = 0;
  $pos = 0;
  $prev = null;

  foreach ($rows as $r) {
    $pos++;
    $score = (int)$r["score"];
    if ($prev === null || $score !== $prev) $rank = $pos;
    $prev = $score;

    $uid = (int)$r["user_id"];
    $ins->bind_param("isiis", $test_id, $generated_at, $uid, $score, $rank);
    // NOTE: bind types should match; easiest is re-prepare with correct types:
  }

  // Fix the binding properly:
  $ins = $conn_meta->prepare("
    INSERT INTO leaderboard_snapshots(test_id, generated_at, user_id, score, penalty, rank_no)
    VALUES(?, ?, ?, ?, 0, ?)
  ");
  if (!$ins) return false;

  $rank = 0; $pos = 0; $prev = null;
  foreach ($rows as $r) {
    $pos++;
    $score = (int)$r["score"];
    if ($prev === null || $score !== $prev) $rank = $pos;
    $prev = $score;

    $uid = (int)$r["user_id"];
    $ins->bind_param("isiii", $test_id, $generated_at, $uid, $score, $rank);
    $ins->execute();
  }

  return true;
}
