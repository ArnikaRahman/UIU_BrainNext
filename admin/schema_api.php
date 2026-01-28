<?php
require_once __DIR__ . "/../includes/auth_admin.php";
header('Content-Type: application/json; charset=utf-8');

// ✅ Allow ONLY your known databases (security)
$ALLOWED_DBS = [
  'uiu_brainnext',
  'uiu_brainnext_meta'
];

$db = $_GET['db'] ?? 'uiu_brainnext_meta';
if (!in_array($db, $ALLOWED_DBS, true)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid database name'], JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * Try to reuse your existing DB connection file if present.
 * If your project already has includes/db.php, keep it.
 * Otherwise, fill fallback credentials below.
 */
$mysqli = null;

// Try common includes (adjust if your path differs)
$possibleIncludes = [
  __DIR__ . '/../includes/db.php',
  __DIR__ . '/../includes/conn.php',
  __DIR__ . '/../includes/config.php',
];

foreach ($possibleIncludes as $inc) {
  if (file_exists($inc)) {
    require_once $inc;
    break;
  }
}

// Detect existing connections (common patterns)
if (isset($conn) && $conn instanceof mysqli) $mysqli = $conn;
if (isset($mysqli_conn) && $mysqli_conn instanceof mysqli) $mysqli = $mysqli_conn;
if (isset($mysqli) && $mysqli instanceof mysqli) $mysqli = $mysqli;

// Fallback connection (EDIT if needed)
if (!$mysqli) {
  $DB_HOST = '127.0.0.1';
  $DB_USER = 'root';
  $DB_PASS = '';
  $DB_PORT = 3306;

  $mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, 'information_schema', $DB_PORT);
  if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $mysqli->connect_error], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// Always query information_schema
$mysqli->select_db('information_schema');

// 1) Tables list
$tables = [];
$stmt = $mysqli->prepare("
  SELECT TABLE_NAME
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = ?
  ORDER BY TABLE_NAME
");
$stmt->bind_param("s", $db);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $tables[$row['TABLE_NAME']] = [
    'name' => $row['TABLE_NAME'],
    'columns' => [],
    // You can later point this to your real CRUD page:
    'url' => 'schema_table.php?db=' . urlencode($db) . '&table=' . urlencode($row['TABLE_NAME'])
  ];
}
$stmt->close();

// 2) Columns for each table
$stmt = $mysqli->prepare("
  SELECT TABLE_NAME, COLUMN_NAME, COLUMN_KEY, DATA_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = ?
  ORDER BY TABLE_NAME, ORDINAL_POSITION
");
$stmt->bind_param("s", $db);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
  $t = $row['TABLE_NAME'];
  if (!isset($tables[$t])) continue;

  $col = $row['COLUMN_NAME'];
  $key = $row['COLUMN_KEY']; // PRI, MUL, UNI, etc.
  $type = $row['DATA_TYPE'];

  $suffix = '';
  if ($key === 'PRI') $suffix = ' (PK)';
  // We'll mark FK later via relations, but keep type visible
  $tables[$t]['columns'][] = $col . ' : ' . $type . $suffix;
}
$stmt->close();

// 3) Foreign key relations
$relations = [];
$stmt = $mysqli->prepare("
  SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = ?
    AND REFERENCED_TABLE_NAME IS NOT NULL
  ORDER BY TABLE_NAME, COLUMN_NAME
");
$stmt->bind_param("s", $db);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $relations[] = [
    'from' => $row['TABLE_NAME'],
    'fromCol' => $row['COLUMN_NAME'],
    'to' => $row['REFERENCED_TABLE_NAME'],
    'toCol' => $row['REFERENCED_COLUMN_NAME'],
  ];
}
$stmt->close();

// Convert tables map → list
$outTables = array_values($tables);

echo json_encode([
  'db' => $db,
  'tables' => $outTables,
  'relations' => $relations
], JSON_UNESCAPED_UNICODE);
