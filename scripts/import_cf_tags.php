<?php
require_once __DIR__ . "/../includes/db.php";

set_time_limit(0);

/* ---------- small schema helpers ---------- */
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
function detect_tag_col(mysqli $conn): string {
  // Most common possibilities
  if (db_has_col($conn, "cf_tags", "name")) return "name";
  if (db_has_col($conn, "cf_tags", "tag")) return "tag";
  if (db_has_col($conn, "cf_tags", "tagname")) return "tagname";
  if (db_has_col($conn, "cf_tags", "title")) return "title";

  // Fallback: pick first non-id VARCHAR/TEXT column
  $db = db_current_name($conn);
  $sql = "
    SELECT COLUMN_NAME, DATA_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME = 'cf_tags'
    ORDER BY ORDINAL_POSITION ASC
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("s", $db);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($row = $rs->fetch_assoc())) {
    $c = (string)$row["COLUMN_NAME"];
    $t = strtolower((string)$row["DATA_TYPE"]);
    if ($c === "id") continue;
    if (in_array($t, ["varchar","text","mediumtext","longtext"], true)) return $c;
  }
  return ""; // fail
}

/* ---------- detect schema ---------- */
$tagCol = detect_tag_col($conn);
if ($tagCol === "") {
  die("ERROR: Could not detect tag column in cf_tags table.\n");
}

if (!db_has_col($conn, "cf_tags", "id")) {
  die("ERROR: cf_tags must have id column (FK uses it).\n");
}
if (!db_has_col($conn, "cf_problem_tags", "problem_id") || !db_has_col($conn, "cf_problem_tags", "tag_id")) {
  die("ERROR: cf_problem_tags must have problem_id and tag_id.\n");
}
if (!db_has_col($conn, "cf_problems", "contest_id") || !db_has_col($conn, "cf_problems", "problem_index")) {
  die("ERROR: cf_problems must have contest_id and problem_index.\n");
}

echo "Using cf_tags column: {$tagCol}\n";
echo "Fetching CF problems with tags...\n";

/* ---------- reset (child first) ---------- */
$conn->query("SET FOREIGN_KEY_CHECKS=0");
$conn->query("TRUNCATE TABLE cf_problem_tags");
$conn->query("TRUNCATE TABLE cf_tags");
$conn->query("SET FOREIGN_KEY_CHECKS=1");

/* ---------- fetch CF API ---------- */
$raw = @file_get_contents("https://codeforces.com/api/problemset.problems");
if ($raw === false) die("CF API request failed\n");

$api = json_decode($raw, true);
if (!is_array($api) || ($api["status"] ?? "") !== "OK") die("CF API failed\n");

$problems = $api["result"]["problems"] ?? [];
if (!is_array($problems)) die("No problems array\n");

$tagSet = [];           // tag => true
$pairs  = [];           // [contest_id, index, tag]

foreach ($problems as $p) {
  $cid  = (int)($p["contestId"] ?? 0);
  $idx  = (string)($p["index"] ?? "");
  $tags = $p["tags"] ?? [];

  if ($cid <= 0 || $idx === "" || !is_array($tags) || empty($tags)) continue;

  foreach ($tags as $tg) {
    $tg = trim((string)$tg);
    if ($tg === "") continue;
    $tagSet[$tg] = true;
    $pairs[] = [$cid, $idx, $tg];
  }
}

echo "Unique tags found: " . count($tagSet) . "  Problem-tag pairs: " . count($pairs) . "\n";

/* ---------- insert tags into correct column ---------- */
$sqlInsertTag = "INSERT INTO cf_tags (`$tagCol`) VALUES (?)";
$stTag = $conn->prepare($sqlInsertTag);
if (!$stTag) die("Prepare failed for cf_tags insert\n");

foreach (array_keys($tagSet) as $tg) {
  $stTag->bind_param("s", $tg);
  $stTag->execute();
}

/* ---------- build tag => id map ---------- */
$sqlMap = "SELECT id, `$tagCol` AS tagname FROM cf_tags";
$res = $conn->query($sqlMap);
$tagId = [];
while ($res && ($r = $res->fetch_assoc())) {
  $tagId[(string)$r["tagname"]] = (int)$r["id"];
}

/* ---------- insert relations ---------- */
$stPT = $conn->prepare("
  INSERT IGNORE INTO cf_problem_tags (problem_id, tag_id)
  SELECT p.id, ?
  FROM cf_problems p
  WHERE p.contest_id = ? AND p.problem_index = ?
");
if (!$stPT) die("Prepare failed for cf_problem_tags\n");

$inserted = 0;
foreach ($pairs as [$cid, $idx, $tg]) {
  if (!isset($tagId[$tg])) continue;
  $tid = (int)$tagId[$tg];
  $stPT->bind_param("iis", $tid, $cid, $idx);
  $stPT->execute();
  if ($stPT->affected_rows > 0) $inserted++;
}

echo "DONE.\n";
echo "Relations inserted: $inserted\n";
echo "Now verify:\n";
echo "SELECT COUNT(*) FROM cf_tags;\n";
echo "SELECT COUNT(*) FROM cf_problem_tags;\n";


