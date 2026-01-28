<?php
define("DB_HOST", "127.0.0.1");
define("DB_USER", "root");
define("DB_PASS", "");
define("DB_NAME", "uiu_brainnext");

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_errno) {
  die("DB connect failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");


