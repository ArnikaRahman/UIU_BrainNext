<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

// Read last Codeforces sync info (saved by admin/cf_sync.php)
function ensure_app_settings_table(mysqli $conn): void {
  $conn->query("CREATE TABLE IF NOT EXISTS app_settings (\n"
    ."  `key` VARCHAR(80) NOT NULL PRIMARY KEY,\n"
    ."  `value` TEXT NULL,\n"
    ."  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n"
    .") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function setting_get(mysqli $conn, string $key, string $default = ""): string {
  $st = $conn->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
  if (!$st) return $default;
  $st->bind_param("s", $key);
  $st->execute();
  $r = $st->get_result();
  $row = $r ? $r->fetch_assoc() : null;
  $st->close();
  return (string)($row["value"] ?? $default);
}

ensure_app_settings_table($conn);
$cf_last_sync_at = setting_get($conn, "cf_last_sync_at", "");
$cf_last_sync_rows = setting_get($conn, "cf_last_sync_rows", "");
$cf_last_sync_msg = setting_get($conn, "cf_last_sync_msg", "");

ui_start("Admin Dashboard", "Admin Panel");
?>

<div class="grid">

  <div class="card col-12">
    <h3 style="margin-bottom:6px;">Administration</h3>
    <div class="muted" style="margin-bottom:12px;">Manage academic data and assignments.</div>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <a class="badge" href="/uiu_brainnext/admin/sections_manage.php">Manage Sections</a>
      <a class="badge" href="/uiu_brainnext/admin/students.php">Manage Students</a>
      <a class="badge" href="/uiu_brainnext/admin/enrollments_manage.php">Enroll Students</a>
      <a class="badge" href="/uiu_brainnext/admin/teacher_manage.php">Manage Teachers</a>
      <a class="badge" href="/uiu_brainnext/admin/cf_sync.php">Sync Codeforces Problems</a>
      <a class="badge" href="/uiu_brainnext/admin/meta_logs.php">Logs</a>
     <!--<a class="badge" href="/uiu_brainnext/admin/nav3d.php">3D Admin Navigation</a> 
     <a class="badge" href="/uiu_brainnext/admin/logs3d.php">3D Audit Trail</a> -->

    </div>

    <div style="height:12px;"></div>
    <div class="card" style="padding:12px 14px; border:1px solid rgba(255,255,255,.10);">
      <div class="muted">Codeforces Last Sync</div>
      <div style="display:flex; gap:18px; flex-wrap:wrap; align-items:baseline;">
        <div style="font-weight:900; font-size:18px;">
          <?= $cf_last_sync_at !== "" ? e($cf_last_sync_at) : "-" ?>
        </div>
        <?php if ($cf_last_sync_rows !== ""): ?>
          <div class="muted">Rows: <b><?= e($cf_last_sync_rows) ?></b></div>
        <?php endif; ?>
        <?php if ($cf_last_sync_msg !== ""): ?>
          <div class="muted">Status: <b><?= e($cf_last_sync_msg) ?></b></div>
        <?php endif; ?>
      </div>
      <div class="muted" style="margin-top:6px;">New problems from Codeforces appear in Student → Global Practice after you run sync.</div>
    </div>
  </div>

  <div class="card col-12">
    <h3 style="margin-bottom:6px;">3D Database Schema</h3>
    <div class="muted" style="margin-bottom:12px;">
      Visualize tables &amp; foreign-key relations in an interactive 3D view (Main DB + Meta DB).
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <a class="badge" href="/uiu_brainnext/admin/schema_3d_demo.php?db=uiu_brainnext">Open Main DB Schema</a>
      <a class="badge" href="/uiu_brainnext/admin/schema_3d_demo.php?db=uiu_brainnext_meta">Open Meta DB Schema</a>
    </div>
  </div>

</div>

<?php ui_end(); ?>









