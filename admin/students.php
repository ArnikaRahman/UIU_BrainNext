<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/layout.php";

ui_start("Students", "Admin • Manage students");

$res = $conn->query("SELECT id, full_name, username, student_id, created_at FROM users WHERE role='student' ORDER BY id DESC");
?>

<div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
  <h2 style="margin:0;">Students</h2>
  <a class="badge" href="/uiu_brainnext/admin/create_student.php">+ Add Student</a>
</div>

<div class="card" style="margin-top:12px;">
  <div class="card-body" style="overflow:auto;">
    <table class="table" style="width:100%; min-width:720px;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Full Name</th>
          <th>Student ID</th>
          <th>Username</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
      <?php while($row = $res->fetch_assoc()): ?>
        <tr>
          <td><?= (int)$row["id"] ?></td>
          <td><?= e($row["full_name"] ?? "") ?></td>
          <td><?= e($row["student_id"] ?? "") ?></td>
          <td><?= e($row["username"] ?? "") ?></td>
          <td><?= e($row["created_at"] ?? "") ?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php ui_end(); ?>
