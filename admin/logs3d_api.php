<?php
require_once __DIR__ . "/../includes/auth_admin.php";
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../includes/db.php"; // your DB connection

// Support different connection variable names
$mysqli = null;
if (isset($conn) && $conn instanceof mysqli) $mysqli = $conn;
if (isset($mysqli_conn) && $mysqli_conn instanceof mysqli) $mysqli = $mysqli_conn;
if (isset($mysqli) && $mysqli instanceof mysqli) $mysqli = $mysqli;

if (!$mysqli) {
  http_response_code(500);
  echo json_encode(["error" => "DB connection not found. Check includes/db.php"], JSON_UNESCAPED_UNICODE);
  exit;
}

function pick_col(array $cols, array $candidates): ?string {
  $lower = array_map('strtolower', $cols);
  foreach ($candidates as $c) {
    $idx = array_search(strtolower($c), $lower, true);
    if ($idx !== false) return $cols[$idx];
  }
  return null;
}

function safe_text($v): string {
  if ($v === null) return "";
  if (is_string($v)) return $v;
  return strval($v);
}

// Check meta_logs exists
$check = $mysqli->query("SHOW TABLES LIKE 'meta_logs'");
if (!$check || $check->num_rows === 0) {
  http_response_code(404);
  echo json_encode(["error" => "Table meta_logs not found"], JSON_UNESCAPED_UNICODE);
  exit;
}

// Read columns
$cols = [];
$res = $mysqli->query("DESCRIBE meta_logs");
while ($r = $res->fetch_assoc()) $cols[] = $r["Field"];

// Detect best columns (works across many schemas)
$idCol = pick_col($cols, ["id", "log_id"]);
$timeCol = pick_col($cols, ["created_at", "logged_at", "timestamp", "time", "at", "ts"]);
$actionCol = pick_col($cols, ["action", "event", "type", "message", "title"]);
$actorCol = pick_col($cols, ["actor", "actor_user_id", "user_id", "admin_id", "username", "performed_by"]);
$entityCol = pick_col($cols, ["entity", "table_name", "table", "module"]);
$entityIdCol = pick_col($cols, ["entity_id", "row_id", "target_id"]);
$detailsCol = pick_col($cols, ["details", "description", "info", "data", "meta"]);
$ipCol = pick_col($cols, ["ip", "ip_address"]);

// Required minimal fields
if (!$timeCol) $timeCol = "NULL";
if (!$actionCol) $actionCol = "NULL";

// Limit
$limit = isset($_GET["limit"]) ? (int)$_GET["limit"] : 250;
if ($limit < 50) $limit = 50;
if ($limit > 800) $limit = 800;

// Build SQL safely (only using detected column names)
$selectParts = [];
$selectParts[] = $idCol ? "`$idCol` AS id" : "NULL AS id";
$selectParts[] = ($timeCol !== "NULL") ? "`$timeCol` AS t" : "NULL AS t";
$selectParts[] = ($actionCol !== "NULL") ? "`$actionCol` AS action" : "NULL AS action";
$selectParts[] = $actorCol ? "`$actorCol` AS actor" : "NULL AS actor";
$selectParts[] = $entityCol ? "`$entityCol` AS entity" : "NULL AS entity";
$selectParts[] = $entityIdCol ? "`$entityIdCol` AS entity_id" : "NULL AS entity_id";
$selectParts[] = $detailsCol ? "`$detailsCol` AS details" : "NULL AS details";
$selectParts[] = $ipCol ? "`$ipCol` AS ip" : "NULL AS ip";

$orderBy = ($timeCol !== "NULL") ? "`$timeCol`" : ($idCol ? "`$idCol`" : "1");

$sql = "SELECT " . implode(", ", $selectParts) . " FROM `meta_logs` ORDER BY $orderBy DESC LIMIT ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $limit);
$stmt->execute();
$q = $stmt->get_result();

$rows = [];
while ($r = $q->fetch_assoc()) {
  $rows[] = [
    "id" => $r["id"],
    "t" => safe_text($r["t"]),              // datetime string
    "action" => safe_text($r["action"]),
    "actor" => safe_text($r["actor"]),
    "entity" => safe_text($r["entity"]),
    "entity_id" => safe_text($r["entity_id"]),
    "details" => safe_text($r["details"]),
    "ip" => safe_text($r["ip"]),
  ];
}
$stmt->close();

// Return newest first (front-end will map to timeline)
echo json_encode([
  "limit" => $limit,
  "count" => count($rows),
  "logs" => $rows,
], JSON_UNESCAPED_UNICODE);
