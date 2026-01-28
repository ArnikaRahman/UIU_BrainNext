<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/layout.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$errors = [];
$success = "";

// CSRF token
if (!isset($_SESSION["csrf"])) {
  $_SESSION["csrf"] = bin2hex(random_bytes(16));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $csrf = $_POST["csrf"] ?? "";
  if (!$csrf || !hash_equals($_SESSION["csrf"], $csrf)) {
    $errors[] = "Invalid request. Please refresh and try again.";
  } else {
    $full_name  = trim($_POST["full_name"] ?? "");
    $student_id = trim($_POST["student_id"] ?? "");
    $username   = trim($_POST["username"] ?? "");  // can be same as student_id
    $password   = (string)($_POST["password"] ?? "");

    if ($full_name === "")  $errors[] = "Full name is required.";
    if ($student_id === "") $errors[] = "Student ID is required.";
    if ($username === "")   $errors[] = "Username is required.";
    if ($password === "" || strlen($password) < 4) $errors[] = "Password must be at least 4 characters.";

    if (!$errors) {
      // check duplicates
      $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR student_id = ? LIMIT 1");
      $stmt->bind_param("ss", $username, $student_id);
      $stmt->execute();
      $exists = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if ($exists) {
        $errors[] = "Student already exists (same username or student ID).";
      } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = "student";

        $stmt = $conn->prepare("INSERT INTO users (username, full_name, student_id, role, password_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $username, $full_name, $student_id, $role, $hash);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
          $success = "Student created successfully!";
          // regenerate csrf to prevent double submit
          $_SESSION["csrf"] = bin2hex(random_bytes(16));
        } else {
          $errors[] = "Database error: " . $conn->error;
        }
      }
    }
  }
}

ui_start("Create Student", "Admin • Add new student");
?>

<div class="card" style="max-width: 720px; margin: 0 auto;">
  <div class="card-header">
    <h2 style="margin:0;">Add Student</h2>
    <p style="margin:6px 0 0; opacity:.8;">Create a new student account for login.</p>
  </div>

  <div class="card-body">
    <?php if ($success): ?>
      <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert danger">
        <ul style="margin:0; padding-left:18px;">
          <?php foreach ($errors as $er): ?>
            <li><?= e($er) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" style="display:grid; gap:12px;">
      <input type="hidden" name="csrf" value="<?= e($_SESSION["csrf"]) ?>">

      <div>
        <label>Full Name</label>
        <input class="input" type="text" name="full_name" required value="<?= e($_POST["full_name"] ?? "") ?>">
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
        <div>
          <label>Student ID</label>
          <input class="input" type="text" name="student_id" required value="<?= e($_POST["student_id"] ?? "") ?>">
        </div>
        <div>
          <label>Username (recommended = Student ID)</label>
          <input class="input" type="text" name="username" required value="<?= e($_POST["username"] ?? "") ?>">
        </div>
      </div>

      <div>
        <label>Password</label>
        <input class="input" type="password" name="password" required>
        <small style="opacity:.75;">Minimum 4 characters. </small>
      </div>

      <div style="display:flex; gap:10px; align-items:center;">
        <button class="badge" type="submit">Create Student</button>
        <a class="badge" href="/uiu_brainnext/admin/dashboard.php">Back</a>
      </div>
    </form>
  </div>
</div>

<?php ui_end(); ?>
