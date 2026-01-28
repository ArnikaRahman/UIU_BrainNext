<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

// optional meta logger
$has_meta_logger = file_exists(__DIR__ . "/../includes/meta_logger.php");
if ($has_meta_logger) require_once __DIR__ . "/../includes/meta_logger.php";

if (session_status() === PHP_SESSION_NONE) session_start();

ui_start("Manage Teachers", "Admin Panel");
ui_top_actions([
  ["Dashboard", "/admin/dashboard.php"],
  ["Manage Sections", "/admin/sections_manage.php"],
  ["Enroll Students", "/admin/enrollments_manage.php"],
  ["Manage Teachers", "/admin/teacher_manage.php"],
  ["Logs", "/admin/meta_logs.php"],
]);

function is_valid_short_username(string $u): bool {
  // short form like: mstr, mis, rahman1, etc.
  // letters/numbers/underscore, 2-30 chars
  return (bool)preg_match('/^[a-zA-Z0-9_]{2,30}$/', $u);
}

$err = get_flash("err");
$ok  = get_flash("ok");

/* -------------------- EDIT mode -------------------- */
$edit_id = (int)($_GET["edit"] ?? 0);
$edit = null;
if ($edit_id > 0) {
  $st = $conn->prepare("SELECT id, username, full_name FROM users WHERE id=? AND role='teacher' LIMIT 1");
  $st->bind_param("i", $edit_id);
  $st->execute();
  $edit = $st->get_result()->fetch_assoc();
}

/* -------------------- CREATE / UPDATE -------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = trim($_POST["action"] ?? "");

  if ($action === "create") {
    $username  = trim($_POST["username"] ?? "");
    $full_name = trim($_POST["full_name"] ?? "");
    $password  = (string)($_POST["password"] ?? "");

    if ($username === "" || $password === "") {
      set_flash("err", "Username and password are required.");
      redirect("/uiu_brainnext/admin/teacher_manage.php");
    }
    if (!is_valid_short_username($username)) {
      set_flash("err", "Username must be 2-30 chars (letters/numbers/underscore only). Example: mstr");
      redirect("/uiu_brainnext/admin/teacher_manage.php");
    }
    if (strlen($password) < 4) {
      set_flash("err", "Password must be at least 4 characters.");
      redirect("/uiu_brainnext/admin/teacher_manage.php");
    }

    // unique username
    $st = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $st->bind_param("s", $username);
    $st->execute();
    if ($st->get_result()->fetch_assoc()) {
      set_flash("err", "This username already exists. Choose another short username.");
      redirect("/uiu_brainnext/admin/teacher_manage.php");
    }

    $ph = password_hash($password, PASSWORD_DEFAULT);
    $role = "teacher";

    $st = $conn->prepare("INSERT INTO users(username, full_name, role, password_hash) VALUES(?,?,?,?)");
    $st->bind_param("ssss", $username, $full_name, $role, $ph);

    if (!$st || !$st->execute()) {
      set_flash("err", "Failed to create teacher.");
      redirect("/uiu_brainnext/admin/teacher_manage.php");
    }

    $new_id = (int)$conn->insert_id;

    if ($has_meta_logger && isset($_SESSION["user"]["id"])) {
      @audit_log((int)$_SESSION["user"]["id"], "admin", "CREATE_TEACHER", "users", $new_id, [
        "username" => $username,
        "full_name" => $full_name
      ]);
    }

    set_flash("ok", "Teacher created successfully.");
    redirect("/uiu_brainnext/admin/teacher_manage.php");
  }

  if ($action === "update") {
    $id        = (int)($_POST["id"] ?? 0);
    $username  = trim($_POST["username"] ?? "");
    $full_name = trim($_POST["full_name"] ?? "");
    $password  = trim((string)($_POST["password"] ?? "")); // optional reset

    if ($id <= 0) {
      set_flash("err", "Invalid teacher id.");
      redirect("/uiu_brainnext/admin/teacher_manage.php");
    }
    if ($username === "") {
      set_flash("err", "Username is required.");
      redirect("/uiu_brainnext/admin/teacher_manage.php?edit=".$id);
    }
    if (!is_valid_short_username($username)) {
      set_flash("err", "Username must be 2-30 chars (letters/numbers/underscore only).");
      redirect("/uiu_brainnext/admin/teacher_manage.php?edit=".$id);
    }

    // ensure teacher exists
    $st = $conn->prepare("SELECT id FROM users WHERE id=? AND role='teacher' LIMIT 1");
    $st->bind_param("i", $id);
    $st->execute();
    if (!$st->get_result()->fetch_assoc()) {
      set_flash("err", "Teacher not found.");
      redirect("/uiu_brainnext/admin/teacher_manage.php");
    }

    // username unique (except this id)
    $st = $conn->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
    $st->bind_param("si", $username, $id);
    $st->execute();
    if ($st->get_result()->fetch_assoc()) {
      set_flash("err", "This username already exists.");
      redirect("/uiu_brainnext/admin/teacher_manage.php?edit=".$id);
    }

    if ($password !== "") {
      if (strlen($password) < 4) {
        set_flash("err", "Password must be at least 4 characters.");
        redirect("/uiu_brainnext/admin/teacher_manage.php?edit=".$id);
      }
      $ph = password_hash($password, PASSWORD_DEFAULT);
      $st = $conn->prepare("UPDATE users SET username=?, full_name=?, password_hash=? WHERE id=? AND role='teacher' LIMIT 1");
      $st->bind_param("sssi", $username, $full_name, $ph, $id);
    } else {
      $st = $conn->prepare("UPDATE users SET username=?, full_name=? WHERE id=? AND role='teacher' LIMIT 1");
      $st->bind_param("ssi", $username, $full_name, $id);
    }

    if (!$st || !$st->execute()) {
      set_flash("err", "Update failed.");
      redirect("/uiu_brainnext/admin/teacher_manage.php?edit=".$id);
    }

    if ($has_meta_logger && isset($_SESSION["user"]["id"])) {
      @audit_log((int)$_SESSION["user"]["id"], "admin", "UPDATE_TEACHER", "users", $id, [
        "username" => $username,
        "full_name" => $full_name,
        "password_reset" => ($password !== "")
      ]);
    }

    set_flash("ok", "Teacher updated successfully.");
    redirect("/uiu_brainnext/admin/teacher_manage.php");
  }
}

/* -------------------- list teachers -------------------- */
$teachers = [];
$r = $conn->query("SELECT id, username, full_name FROM users WHERE role='teacher' ORDER BY COALESCE(full_name, username) ASC, username ASC");
while ($r && ($row = $r->fetch_assoc())) $teachers[] = $row;
?>

<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

<div class="grid">
  <div class="card col-6">
    <h3><?= $edit ? "Edit Teacher" : "Create Teacher" ?></h3>
    <div class="muted">Username must be short form (example: <b>mstr</b>, <b>mis</b>, <b>shafqat</b>).</div>
    <div style="height:12px;"></div>

    <form method="POST">
      <input type="hidden" name="action" value="<?= $edit ? "update" : "create" ?>">
      <?php if ($edit): ?>
        <input type="hidden" name="id" value="<?= (int)$edit["id"] ?>">
      <?php endif; ?>

      <label class="label">Username (short form)</label>
      <input name="username" required placeholder="e.g. mstr"
             value="<?= $edit ? e($edit["username"]) : "" ?>">

      <div style="height:10px;"></div>

      <label class="label">Full Name</label>
      <input name="full_name" placeholder="e.g. Md. Shafqat Talukder"
             value="<?= $edit ? e($edit["full_name"]) : "" ?>">

      <div style="height:10px;"></div>

      <label class="label"><?= $edit ? "Reset Password (optional)" : "Password" ?></label>
      <input name="password" type="password" <?= $edit ? "" : "required" ?>
             placeholder="<?= $edit ? "Leave blank to keep current password" : "Set teacher password" ?>">

      <div style="height:14px;"></div>

      <button class="btn-primary" type="submit" style="width:100%;">
        <?= $edit ? "Update Teacher" : "Create Teacher" ?>
      </button>

      <?php if ($edit): ?>
        <div style="height:10px;"></div>
        <a class="badge" href="/uiu_brainnext/admin/teacher_manage.php">Cancel Edit</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="card col-6">
    <h3>All Teachers</h3>
    <div class="muted">Teachers created here will appear in <b>Manage Sections → Teacher dropdown</b>.</div>
    <div style="height:12px;"></div>

    <?php if (empty($teachers)): ?>
      <div class="muted">No teachers found.</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Teacher</th>
            <th>Username</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($teachers as $t): ?>
            <tr>
              <td style="font-weight:900;"><?= e($t["full_name"] ?: "—") ?></td>
              <td class="muted"><?= e($t["username"]) ?></td>
              <td style="text-align:right;">
                <a class="badge" href="/uiu_brainnext/admin/teacher_manage.php?edit=<?= (int)$t["id"] ?>">Edit</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php ui_end(); ?>




