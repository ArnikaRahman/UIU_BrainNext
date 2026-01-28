<?php
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auth_admin.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $role = $_POST["role"] ?? "";
  $full_name = trim($_POST["full_name"] ?? "");
  $student_id = trim($_POST["student_id"] ?? "");
  $password = $_POST["password"] ?? "";
  $custom_username = trim($_POST["custom_username"] ?? "");

  if (!in_array($role, ["student","teacher","admin"], true)) {
    set_flash("err", "Invalid role.");
    redirect("/uiu_brainnext/admin/users_create.php");
  }
  if ($full_name === "" || $password === "") {
    set_flash("err", "Full name and password are required.");
    redirect("/uiu_brainnext/admin/users_create.php");
  }

  // username rules
  if ($role === "student") {
    if ($student_id === "") {
      set_flash("err", "Student ID is required for student accounts.");
      redirect("/uiu_brainnext/admin/users_create.php");
    }
    $username = $student_id; // username = student_id
  } elseif ($role === "teacher") {
    // allow override; else auto-generate short form
    $username = ($custom_username !== "")
      ? strtolower(preg_replace('/\s+/', '', $custom_username))
      : generate_teacher_username($full_name, $conn);
  } else { // admin
    if ($custom_username === "") {
      set_flash("err", "Admin username is required (use custom username).");
      redirect("/uiu_brainnext/admin/users_create.php");
    }
    $username = strtolower(preg_replace('/\s+/', '', $custom_username));
  }

  // Basic username validation
  if (!preg_match('/^[a-z0-9_]+$/', $username)) {
    set_flash("err", "Username must be lowercase letters/numbers/underscore only.");
    redirect("/uiu_brainnext/admin/users_create.php");
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);

  $stmt = $conn->prepare("INSERT INTO users(username, full_name, student_id, role, password_hash) VALUES(?,?,?,?,?)");
  $student_id_or_null = ($role === "student") ? $student_id : null;
  $stmt->bind_param("sssss", $username, $full_name, $student_id_or_null, $role, $hash);

  if ($stmt->execute()) {
    set_flash("ok", "User created! Username: " . $username);
    redirect("/uiu_brainnext/admin/users_create.php");
  } else {
    set_flash("err", "Failed. Username/StudentID may already exist.");
    redirect("/uiu_brainnext/admin/users_create.php");
  }
}

$err = get_flash("err");
$ok  = get_flash("ok");
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Create Users</title>
  <link rel="stylesheet" href="/uiu_brainnext/assets/css/style.css">
</head>
<body>
  <div class="container">
    <div class="nav">
      <div class="left">
        <a class="badge" href="/uiu_brainnext/admin/dashboard.php">← Dashboard</a>
        <div class="muted">Create Student/Teacher/Admin</div>
      </div>
      <a class="badge" href="/uiu_brainnext/logout.php">Logout</a>
    </div>

    <div class="card">
      <?php if ($err): ?><div class="alert err"><?=e($err)?></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert ok"><?=e($ok)?></div><?php endif; ?>

      <form method="POST" class="grid">
        <div class="col-6">
          <label class="muted">Role</label>
          <select name="role" required>
            <option value="student">student</option>
            <option value="teacher">teacher</option>
            <option value="admin">admin</option>
          </select>
        </div>

        <div class="col-6">
          <label class="muted">Full Name</label>
          <input name="full_name" required>
        </div>

        <div class="col-6">
          <label class="muted">Student ID (only for student)</label>
          <input name="student_id" placeholder="e.g. 011231045">
        </div>

        <div class="col-6">
          <label class="muted">Custom Username (optional for teacher, required for admin)</label>
          <input name="custom_username" placeholder="e.g. mhr or admin1">
        </div>

        <div class="col-12">
          <label class="muted">Password</label>
          <input name="password" type="password" required>
        </div>

        <div class="col-12">
          <button type="submit">Create User</button>
          <p class="muted" style="margin-top:10px">
            Teacher username auto rule: initials from full name (unique by adding numbers).
          </p>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
