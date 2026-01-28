<?php
// Meta database connection (uiu_brainnext_meta)
// Used for AI cache + audit logs. App should still run even if this DB is missing.

$META_DB_HOST = "127.0.0.1";
$META_DB_PORT = 3306; // change if your MySQL runs on 3307 etc.
$META_DB_USER = "root";
$META_DB_PASS = "";
$META_DB_NAME = "uiu_brainnext_meta";

// IMPORTANT: ai_meta_store.php expects $conn_meta
$conn_meta = null;
$META_DB_ERROR = null;

mysqli_report(MYSQLI_REPORT_OFF);
$tmp = new mysqli($META_DB_HOST, $META_DB_USER, $META_DB_PASS, $META_DB_NAME, $META_DB_PORT);

if ($tmp && !$tmp->connect_error) {
  $tmp->set_charset("utf8mb4");
  $conn_meta = $tmp;
} else {
  $META_DB_ERROR = $tmp ? $tmp->connect_error : "Unknown meta DB connection error";
  $conn_meta = null;
}




