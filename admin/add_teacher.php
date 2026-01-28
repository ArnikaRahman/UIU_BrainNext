<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/layout.php";

// Optional (safe): audit log into uiu_brainnext_meta if connected
require_once __DIR__ . "/../includes/meta_logger.php";

if (session_status() === PHP_SESSION_NONE) session_start();

ui_start("Add Teacher", "Admin Panel");
ui_top_actions([
  ["Dashboard", "/admin/dashboard.php"],
  ["Manage Sections", "/admin/sections_manage.php"],
  ["Enroll Students", "/admin/enrollments_manage.php"],
  ["Logs", "/admin/meta_logs.php"],
]);

$ok  = get_flash("ok");
$err = get_flash("err");

/* ---------------- Helpers ---------------- */
function clean_short_username(string $u): string {
  $u = trim($u);
  $u = strtolower($u);
  // keep only a-z 0-9 _
  $u = preg_replace('/[^a-z0-9_]/', '', $u);
  return (string)$u;
}

/* ---------------- Create Teacher ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $full_name = trim($_POST["full_name"] ?? "");
  $username  = clean_short_username($_POST["username"] ?? "");
  $pass      = (string)($_POST["password"] ?? "");
  $pass2     = (string)($_POST["password2"] ?? "");

  if ($full_name === "" || $username === "" || $pass === "" || $pass2 === "") {
    set_flash("err", "All fields are required.");
    redirect("/uiu_brainnext/admin/add_teacher.php");
  }

  // short username rules (you can change length)
  if (!preg_match('/^[a-z0-9_]{2,20}$/', $username)) {
    set_flash("err", "Username must be 2-20 chars (a-z, 0-9, underscore). Example: mstr, ms_shafqat");
    redirect("/uiu_brainnext/admin/add_teacher.php");
  }

  if (strlen($pass) < 6) {
    set_flash("err", "Password must be at least 6 characters.");
    redirect("/uiu_brainnext/admin/add_teacher.php");
  }

  if ($pass !== $pass2) {
    set_flash("err", "Password and Confirm Password do not match.");
    redirect("/uiu_brainnext/admin/add_teacher.php");
  }

  // username unique
  $st = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
  $st->bind_param("s", $username);
  $st->execute();
  $exists = $st->get_result()->fetch_assoc();

  if ($exists) {
    set_flash("err", "This username already exists. Try another short form.");
    redirect("/uiu_brainnext/admin/add_teacher.php");
  }

  $hash = password_hash($pass, PASSWORD_DEFAULT);

  // insert teacher
  $role = "teacher";
  $st = $conn->prepare("INSERT INTO users(username, full_name, role, password_hash) VALUES(?,?,?,?)");
  $st->bind_param("ssss", $username, $full_name, $role, $hash);

  if (!$st->execute()) {
    set_flash("err", "Failed to create teacher. Error: " . $conn->error);
    redirect("/uiu_brainnext/admin/add_teacher.php");
  }

  $new_id = (int)$conn->insert_id;

  // audit log (meta db) - safe if meta not connected
  audit_log(
    (int)($_SESSION["user"]["id"] ?? 0),
    "admin",
    "CREATE_TEACHER",
    "users",
    $new_id,
    ["teacher_username" => $username, "teacher_full_name" => $full_name]
  );

  set_flash("ok", "Teacher created successfully: {$full_name} ({$username}). Now assign in Manage Sections.");
  redirect("/uiu_brainnext/admin/add_teacher.php");
}

/* ---------------- List teachers ---------------- */
$q = trim($_GET["q"] ?? "");
$teachers = [];

if ($q !== "") {
  $like = "%".$q."%";
  $st = $conn->prepare("
    SELECT id, username, full_name
    FROM users
    WHERE role='teacher'
      AND (username LIKE ? OR full_name LIKE ?)
    ORDER BY id DESC
    LIMIT 100
  ");
  $st->bind_param("ss", $like, $like);
} else {
  $st = $conn->prepare("
    SELECT id, username, full_name
    FROM users
    WHERE role='teacher'
    ORDER BY id DESC
    LIMIT 100
  ");
}

$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $teachers[] = $row;
?>

<style>
/* small helper style for the right-side list header */
.split{
  display:grid;
  grid-template-columns: 1.1fr .9fr;
  gap:16px;
}
@media (max-width: 1000px){
  .split{grid-template-columns:1fr;}
}
</style>

<div class="split">

  <!-- LEFT: CREATE FORM -->
  <div class="card">
    <h3 style="margin-bottom:6px;">Add New Teacher</h3>
    <div class="muted">Username must be the <b>short form</b> (example: <b>mstr</b>, <b>mis</b>, <b>shaf</b>).</div>

    <div style="height:12px;"></div>

    <?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>

    <form method="POST">
      <label class="label">Teacher Full Name</label>
      <input name="full_name" id="full_name" type="text" placeholder="e.g. Md. Shafqat Talukder" required>

      <div style="height:10px;"></div>

      <label class="label">Teacher Username (short)</label>
      <input name="username" id="username" type="text" placeholder="e.g. mstr" required>
      <div class="muted" style="margin-top:6px; font-size:12px;">
        Allowed: a-z, 0-9, underscore. 2–20 chars.
      </div>

      <div style="height:10px;"></div>

      <label class="label">Password</label>
      <input name="password" type="password" placeholder="min 6 chars" required>

      <div style="height:10px;"></div>

      <label class="label">Confirm Password</label>
      <input name="password2" type="password" placeholder="retype password" required>

      <div style="height:14px;"></div>

      <button class="btn-primary" type="submit">Create Teacher</button>
      <a class="badge" href="/uiu_brainnext/admin/sections_manage.php" style="margin-left:10px;">Assign in Sections</a>
    </form>

    <script>
      // Auto-suggest short username from full name (editable)
      (function(){
        const full = document.getElementById("full_name");
        const user = document.getElementById("username");

        function suggest(){
          const name = (full.value || "").trim();
          if(!name) return;

          // take first letter of each word (max 6), lowercase
          const parts = name.split(/\s+/).filter(Boolean);
          let shorty = parts.map(p => p[0] ? p[0].toLowerCase() : "").join("");
          shorty = shorty.replace(/[^a-z0-9_]/g,"").slice(0, 8);

          // only auto-fill if username empty
          if(!user.value.trim()){
            user.value = shorty;
          }
        }

        full.addEventListener("blur", suggest);
      })();
    </script>
  </div>

  <!-- RIGHT: TEACHER LIST -->
  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div>
        <h3 style="margin-bottom:6px;">Existing Teachers</h3>
        <div class="muted">These teachers appear in “Manage Sections” dropdown.</div>
      </div>

      <form method="GET" style="display:flex; gap:10px; align-items:flex-end;">
        <div>
          <label class="label">Search</label>
          <input name="q" value="<?= e($q) ?>" placeholder="name or username">
        </div>
        <button class="btn-primary" type="submit" style="height:42px;">Search</button>
      </form>
    </div>

    <div style="height:12px;"></div>

    <?php if (empty($teachers)): ?>
      <div class="muted">No teachers found.</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Full Name</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($teachers as $t): ?>
            <tr>
              <td style="font-weight:900;"><?= (int)$t["id"] ?></td>
              <td style="font-weight:900;"><?= e($t["username"]) ?></td>
              <td><?= e($t["full_name"] ?? "") ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<?php ui_end(); ?>
