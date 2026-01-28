<?php
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/meta_logger.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* ------------------ DB connection check ------------------ */
if (!($conn instanceof mysqli) || $conn->connect_errno) {
  die("DB connection failed: " . htmlspecialchars($conn->connect_error ?? "unknown error"));
}

/* ------------------ helpers ------------------ */
function table_exists(mysqli $conn, string $table): bool {
  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("s", $table);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}

function has_col(mysqli $conn, string $table, string $col): bool {
  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("ss", $table, $col);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}

function pick_first_col(mysqli $conn, string $table, array $cands, string $fallback): string {
  foreach ($cands as $c) {
    if (has_col($conn, $table, $c)) return $c;
  }
  return $fallback;
}

function list_tables(mysqli $conn): array {
  $out = [];
  $rs = $conn->query("SHOW TABLES");
  if ($rs) {
    while ($row = $rs->fetch_row()) $out[] = (string)$row[0];
  }
  return $out;
}

/* ------------------ detect user table ------------------ */
$userTableCandidates = ["users", "user", "app_users", "accounts", "students", "teachers"];
$userTable = null;

foreach ($userTableCandidates as $t) {
  if (table_exists($conn, $t)) { $userTable = $t; break; }
}

if ($userTable === null) {
  $tables = list_tables($conn);
  die(
    "No user table found. I searched: <b>" . htmlspecialchars(implode(", ", $userTableCandidates)) . "</b><br>" .
    "Tables in this DB: <pre>" . htmlspecialchars(implode("\n", $tables)) . "</pre>"
  );
}

/* ------------------ already logged in? redirect ------------------ */
if (isset($_SESSION["user"])) {
  $role = $_SESSION["user"]["role"] ?? "";
  if ($role === "admin")   redirect("/uiu_brainnext/admin/dashboard.php");
  if ($role === "teacher") redirect("/uiu_brainnext/teacher/dashboard.php");
  if ($role === "student") redirect("/uiu_brainnext/student/dashboard.php");
  session_destroy();
}

/* ------------------ flash ------------------ */
$err = get_flash("err");

/* ------------------ handle login ------------------ */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim($_POST["username"] ?? "");
  $password = (string)($_POST["password"] ?? "");

  if ($username === "" || $password === "") {
    set_flash("err", "Username and password are required.");
    redirect("/uiu_brainnext/index.php");
  }

  // detect columns safely
  $fullNameCol = pick_first_col($conn, $userTable, ["full_name", "name", "fullname"], "username");
  $roleCol     = pick_first_col($conn, $userTable, ["role", "user_role", "type"], "role");
  $passCol     = pick_first_col($conn, $userTable, ["password_hash", "pass_hash", "password"], "password_hash");

  $sql = "
    SELECT
      id,
      username,
      `$fullNameCol` AS full_name,
      `$roleCol` AS role,
      `$passCol` AS password_hash
    FROM `$userTable`
    WHERE username = ?
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);

  // ✅ Important: if table exists but "doesn't exist in engine", prepare may fail here
  if (!$stmt) {
    $tables = list_tables($conn);
    die(
      "Prepare failed: " . htmlspecialchars($conn->error) . "<br><br>" .
      "This often means the table is corrupted (InnoDB crash).<br>" .
      "User table detected: <b>" . htmlspecialchars($userTable) . "</b><br>" .
      "Tables in DB:<pre>" . htmlspecialchars(implode("\n", $tables)) . "</pre>" .
      "<hr><pre>" . htmlspecialchars($sql) . "</pre>"
    );
  }

  $stmt->bind_param("s", $username);
  $stmt->execute();
  $u = $stmt->get_result()->fetch_assoc();

  if (!$u) {
    if (function_exists("log_login_attempt")) log_login_attempt(null, $username, false);
    set_flash("err", "Invalid username or password.");
    redirect("/uiu_brainnext/index.php");
  }

  // password check: hashed or plain
  $dbPass = (string)($u["password_hash"] ?? "");
  $okPass = false;

  if ($dbPass !== "" && (str_starts_with($dbPass, '$2y$') || str_starts_with($dbPass, '$argon2'))) {
    $okPass = password_verify($password, $dbPass);
  } else {
    $okPass = hash_equals($dbPass, $password);
  }

  if (!$okPass) {
    if (function_exists("log_login_attempt")) log_login_attempt((int)$u["id"], $username, false);
    set_flash("err", "Invalid username or password.");
    redirect("/uiu_brainnext/index.php");
  }

  if (function_exists("log_login_attempt")) log_login_attempt((int)$u["id"], $username, true);

  $_SESSION["user"] = [
    "id"        => (int)$u["id"],
    "username"  => (string)$u["username"],
    "full_name" => (string)($u["full_name"] ?? $u["username"]),
    "role"      => (string)($u["role"] ?? "student"),
  ];

  $r = (string)($_SESSION["user"]["role"] ?? "");
  if ($r === "admin")   redirect("/uiu_brainnext/admin/dashboard.php");
  if ($r === "teacher") redirect("/uiu_brainnext/teacher/dashboard.php");
  redirect("/uiu_brainnext/student/dashboard.php");
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>UIU BrainNext - Login</title>
  <link rel="stylesheet" href="/uiu_brainnext/assets/css/style.css?v=1">
</head>
<body>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-pill">UIU BrainNext</div>

      <h1 class="auth-title">Login</h1>
      <p class="auth-subtitle muted">Student / Teacher / Admin</p>

      <?php if ($err): ?>
        <div class="alert err"><?= e($err) ?></div>
      <?php endif; ?>

      <form method="POST">
        <label class="label">Username</label>
        <input name="username" type="text" placeholder="Student ID / Teacher short / Admin" required>

        <div style="height:10px;"></div>

        <label class="label">Password</label>
        <input name="password" type="password" placeholder="Enter password" required>

        <div style="height:14px;"></div>
        <button class="btn-primary" type="submit">Login</button>
      </form>

      <div class="muted" style="margin-top:14px; font-size:12px;">
        Teacher & student accounts are created by admin.
      </div>
    </div>
  </div>
</body>
</html>








