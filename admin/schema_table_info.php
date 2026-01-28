<?php
require_once __DIR__ . "/../includes/auth_admin.php";
header("Content-Type: application/json; charset=utf-8");

/* allow only known DBs */
$ALLOWED_DBS = ["uiu_brainnext", "uiu_brainnext_meta"];

$db = $_GET["db"] ?? "uiu_brainnext";
$table = $_GET["table"] ?? "";

if (!in_array($db, $ALLOWED_DBS, true)) {
  http_response_code(400);
  echo json_encode(["error" => "Invalid database"], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($table === "" || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
  http_response_code(400);
  echo json_encode(["error" => "Invalid table"], JSON_UNESCAPED_UNICODE);
  exit;
}

/* get mysqli from your project (same style as schema_api.php) */
$mysqli = null;

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

if (isset($conn) && $conn instanceof mysqli) $mysqli = $conn;
if (isset($mysqli_conn) && $mysqli_conn instanceof mysqli) $mysqli = $mysqli_conn;
if (isset($mysqli) && $mysqli instanceof mysqli) $mysqli = $mysqli;

if (!$mysqli) {
  $mysqli = @new mysqli("127.0.0.1", "root", "", "information_schema", 3306);
  if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "DB connection failed: " . $mysqli->connect_error], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* verify table exists in db */
$mysqli->select_db("information_schema");
$st = $mysqli->prepare("
  SELECT TABLE_ROWS
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = ?
    AND TABLE_NAME = ?
  LIMIT 1
");
$st->bind_param("ss", $db, $table);
$st->execute();
$r = $st->get_result()->fetch_assoc();
$st->close();

if (!$r) {
  http_response_code(404);
  echo json_encode(["error" => "Table not found"], JSON_UNESCAPED_UNICODE);
  exit;
}

$row_count = (int)($r["TABLE_ROWS"] ?? 0);

/* columns */
$columns = [];
$st = $mysqli->prepare("
  SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = ?
    AND TABLE_NAME = ?
  ORDER BY ORDINAL_POSITION
");
$st->bind_param("ss", $db, $table);
$st->execute();
$res = $st->get_result();
while ($row = $res->fetch_assoc()) {
  $columns[] = [
    "name" => $row["COLUMN_NAME"],
    "type" => $row["COLUMN_TYPE"],
    "nullable" => $row["IS_NULLABLE"],
    "key" => $row["COLUMN_KEY"],
    "default" => $row["COLUMN_DEFAULT"],
    "extra" => $row["EXTRA"],
  ];
}
$st->close();

/* indexes */
$indexes = [];
$st = $mysqli->prepare("
  SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = ?
    AND TABLE_NAME = ?
  ORDER BY INDEX_NAME, SEQ_IN_INDEX
");
$st->bind_param("ss", $db, $table);
$st->execute();
$res = $st->get_result();

$tmp = [];
while ($row = $res->fetch_assoc()) {
  $iname = $row["INDEX_NAME"];
  if (!isset($tmp[$iname])) $tmp[$iname] = [];
  $tmp[$iname][] = $row["COLUMN_NAME"];
}
$st->close();

foreach ($tmp as $iname => $cols) {
  $indexes[] = [
    "name" => $iname,
    "columns" => implode(", ", $cols),
  ];
}

/* foreign keys + rules */
$foreign_keys = [];
$st = $mysqli->prepare("
  SELECT
    kcu.CONSTRAINT_NAME,
    kcu.TABLE_NAME AS from_table,
    kcu.COLUMN_NAME AS from_column,
    kcu.REFERENCED_TABLE_NAME AS to_table,
    kcu.REFERENCED_COLUMN_NAME AS to_column,
    rc.UPDATE_RULE AS on_update,
    rc.DELETE_RULE AS on_delete
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
  LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
    ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
  WHERE kcu.TABLE_SCHEMA = ?
    AND kcu.TABLE_NAME = ?
    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
  ORDER BY kcu.COLUMN_NAME
");
$st->bind_param("ss", $db, $table);
$st->execute();
$res = $st->get_result();
while ($row = $res->fetch_assoc()) {
  $foreign_keys[] = [
    "constraint" => $row["CONSTRAINT_NAME"],
    "from_table" => $row["from_table"],
    "from_column" => $row["from_column"],
    "to_table" => $row["to_table"],
    "to_column" => $row["to_column"],
    "on_update" => $row["on_update"] ?? "",
    "on_delete" => $row["on_delete"] ?? "",
  ];
}
$st->close();

/* sample rows (top 5) */
$sample_rows = [];
$sample_cols = [];

try {
  $mysqli->select_db($db);

  $safeTable = str_replace("`", "``", $table);
  $q = $mysqli->query("SELECT * FROM `$safeTable` LIMIT 5");

  if ($q) {
    $sample_cols = [];
    $finfo = $q->fetch_fields();
    foreach ($finfo as $f) $sample_cols[] = $f->name;

    while ($row = $q->fetch_assoc()) {
      // keep values small in JSON
      foreach ($row as $k => $v) {
        if (is_null($v)) continue;
        $s = (string)$v;
        if (strlen($s) > 240) $row[$k] = substr($s, 0, 240) . "…";
      }
      $sample_rows[] = $row;
    }
  }
} catch (Throwable $e) {
  // ignore sample errors
}

echo json_encode([
  "db" => $db,
  "table" => $table,
  "row_count" => $row_count,
  "columns" => $columns,
  "indexes" => $indexes,
  "foreign_keys" => $foreign_keys,
  "sample_cols" => $sample_cols,
  "sample_rows" => $sample_rows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
