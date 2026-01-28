<?php
require_once __DIR__ . "/../includes/auth_admin.php";
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../includes/db.php"; // use your project DB connection

// Adjust these variable names if your db.php uses different connection var
$mysqli = $conn ?? $mysqli ?? null;
if (!$mysqli || !($mysqli instanceof mysqli)) {
  echo json_encode(["error" => "DB connection not found in includes/db.php"]);
  exit;
}

function scalar(mysqli $db, string $sql): int {
  $res = $db->query($sql);
  if (!$res) return 0;
  $row = $res->fetch_row();
  return (int)($row[0] ?? 0);
}

// Use whatever tables you actually have.
// If a table doesn't exist, count will just be 0 (safe).
$out = [
  "sections"     => scalar($mysqli, "SELECT COUNT(*) FROM sections"),
  "enrollments"  => scalar($mysqli, "SELECT COUNT(*) FROM enrollments"),
  "teachers"     => scalar($mysqli, "SELECT COUNT(*) FROM users WHERE role='teacher'"),
  "logs"         => scalar($mysqli, "SELECT COUNT(*) FROM meta_logs"),

  // Optional (if you add later):
  // "submissions"  => scalar($mysqli, "SELECT COUNT(*) FROM submissions"),
  // "problems"     => scalar($mysqli, "SELECT COUNT(*) FROM problems"),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE);
