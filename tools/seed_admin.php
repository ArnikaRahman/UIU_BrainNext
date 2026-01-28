<?php
require_once __DIR__ . "/../includes/db.php";

$ADMIN_USERNAME = "admin";
$ADMIN_FULLNAME = "System Admin";
$ADMIN_PASSWORD = "admin123"; // change after login

$hash = password_hash($ADMIN_PASSWORD, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT IGNORE INTO users(username, full_name, student_id, role, password_hash) VALUES(?,?,NULL,'admin',?)");
$stmt->bind_param("sss", $ADMIN_USERNAME, $ADMIN_FULLNAME, $hash);
$stmt->execute();

echo "Admin ensured. Username: admin | Password: admin123 (Change later). Now delete tools/seed_admin.php";
