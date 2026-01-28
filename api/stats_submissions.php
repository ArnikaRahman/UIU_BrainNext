<?php
// /uiu_brainnext/api/stats_submissions.php

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . "/../includes/db.php";        // $conn
require_once __DIR__ . "/../includes/functions.php"; // e()

header("Content-Type: application/json; charset=utf-8");

// allow only teacher/admin
$role = $_SESSION["user"]["role"] ?? "";
if (!in_array($role, ["teacher", "admin"], true)) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Forbidden"]);
  exit;
}

/* ---------------- helpers ---------------- */
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

function pick_first_col(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (db_has_col($conn, $table, $c)) return $c;
  }
  return null;
}

/* ---------------- inputs ---------------- */
$days = (int)($_GET["days"] ?? 7);
if ($days < 1) $days = 7;
if ($days > 90) $days = 90;

/* ---------------- schema detect ---------------- */
$time_col   = pick_first_col($conn, "submissions", ["submitted_at", "created_at", "submitted_time"]) ?? null;
$has_verdict = db_has_col($conn, "submissions", "verdict");

if (!$time_col) {
  // still works but without time filtering
  $time_filter_sql = "";
} else {
  $time_filter_sql = " AND s.`$time_col` >= (NOW() - INTERVAL ? DAY) ";
}

/* ---------------- query ----------------
   Aggregated by course:
   total, AC, WA, CE, RE, TLE, MANUAL
*/
$selectVerdicts = "";
if ($has_verdict) {
  $selectVerdicts = ",
    SUM(CASE WHEN UPPER(COALESCE(s.verdict,''))='AC' THEN 1 ELSE 0 END)     AS ac,
    SUM(CASE WHEN UPPER(COALESCE(s.verdict,''))='WA' THEN 1 ELSE 0 END)     AS wa,
    SUM(CASE WHEN UPPER(COALESCE(s.verdict,''))='CE' THEN 1 ELSE 0 END)     AS ce,
    SUM(CASE WHEN UPPER(COALESCE(s.verdict,''))='RE' THEN 1 ELSE 0 END)     AS re,
    SUM(CASE WHEN UPPER(COALESCE(s.verdict,''))='TLE' THEN 1 ELSE 0 END)    AS tle,
    SUM(CASE WHEN UPPER(COALESCE(s.verdict,''))='MANUAL' THEN 1 ELSE 0 END) AS manual
  ";
} else {
  // if verdict column not present, return zeros
  $selectVerdicts = ",
    0 AS ac, 0 AS wa, 0 AS ce, 0 AS re, 0 AS tle, 0 AS manual
  ";
}

$sql = "
  SELECT
    c.id AS course_id,
    c.code AS course_code,
    c.title AS course_title,
    COUNT(*) AS total
    $selectVerdicts
  FROM submissions s
  JOIN problems p ON p.id = s.problem_id
  JOIN courses  c ON c.id = p.course_id
  WHERE 1=1
  $time_filter_sql
  GROUP BY c.id, c.code, c.title
  ORDER BY total DESC, c.code ASC
  LIMIT 50
";

$st = $conn->prepare($sql);
if (!$st) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "SQL prepare failed", "detail" => $conn->error]);
  exit;
}

if ($time_col) {
  $st->bind_param("i", $days);
}

$st->execute();
$res = $st->get_result();

$out = [];
while ($res && ($row = $res->fetch_assoc())) {
  $out[] = [
    "course_id" => (int)$row["course_id"],
    "course_code" => (string)$row["course_code"],
    "course_title" => (string)$row["course_title"],
    "total" => (int)$row["total"],
    "ac" => (int)($row["ac"] ?? 0),
    "wa" => (int)($row["wa"] ?? 0),
    "ce" => (int)($row["ce"] ?? 0),
    "re" => (int)($row["re"] ?? 0),
    "tle" => (int)($row["tle"] ?? 0),
    "manual" => (int)($row["manual"] ?? 0),
  ];
}

echo json_encode([
  "ok" => true,
  "days" => $days,
  "count" => count($out),
  "rows" => $out
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
