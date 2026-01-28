<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* =========================
   ✅ Persist last sync info
   We store in a tiny key/value table: app_settings
   so Admin Dashboard can show Last Sync time.
   ========================= */

function ensure_app_settings_table(mysqli $conn): void {
  $conn->query("CREATE TABLE IF NOT EXISTS app_settings (\n"
    ."  `key` VARCHAR(80) NOT NULL PRIMARY KEY,\n"
    ."  `value` TEXT NULL,\n"
    ."  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n"
    .") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function setting_get(mysqli $conn, string $key, string $default=""): string {
  $st = $conn->prepare("SELECT `value` FROM app_settings WHERE `key`=? LIMIT 1");
  if (!$st) return $default;
  $st->bind_param("s", $key);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  return (string)($row["value"] ?? $default);
}

function setting_set(mysqli $conn, string $key, string $value): void {
  $st = $conn->prepare("INSERT INTO app_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
  if (!$st) return;
  $st->bind_param("ss", $key, $value);
  $st->execute();
}

/**
 * Manual Codeforces Sync (Admin)
 * - GET  : shows a page + button
 * - POST : runs sync and shows results
 */

function cf_get_json(string $url): array {
  $ctx = stream_context_create([
    "http" => [
      "timeout" => 25,
      "header"  => "User-Agent: UIUBrainNext/1.0\r\n"
    ]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) return ["status" => "FAILED", "comment" => "Request failed"];
  $json = json_decode($raw, true);
  return is_array($json) ? $json : ["status" => "FAILED", "comment" => "Bad JSON"];
}

function db_current_name(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db");
  $row = $r ? $r->fetch_assoc() : null;
  return (string)($row["db"] ?? "");
}
function db_has_table(mysqli $conn, string $table): bool {
  $db = db_current_name($conn);
  if ($db === "") return false;
  $st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1");
  if (!$st) return false;
  $st->bind_param("ss", $db, $table);
  $st->execute();
  $res = $st->get_result();
  return (bool)$res->fetch_row();
}

$has_cf = db_has_table($conn, "cf_problems");

// Ensure settings storage exists
ensure_app_settings_table($conn);

$last_sync_at   = setting_get($conn, "cf_last_sync_at", "");
$last_sync_rows = setting_get($conn, "cf_last_sync_rows", "");
$last_sync_msg  = setting_get($conn, "cf_last_sync_msg", "");

$result = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (!$has_cf) {
    $result = ["ok" => false, "msg" => "Missing table: cf_problems. Run your migration first."];
  } else {
    $data = cf_get_json("https://codeforces.com/api/problemset.problems");
    if (($data["status"] ?? "") !== "OK") {
      $result = ["ok" => false, "msg" => "CF API error: " . ($data["comment"] ?? "Unknown")];
    } else {
      $problems = $data["result"]["problems"] ?? [];
      $stats    = $data["result"]["problemStatistics"] ?? [];

      // map: contestId+index => solvedCount
      $solvedMap = [];
      foreach ($stats as $s) {
        $cid = (int)($s["contestId"] ?? 0);
        $idx = (string)($s["index"] ?? "");
        $key = $cid . "_" . $idx;
        $solvedMap[$key] = (int)($s["solvedCount"] ?? 0);
      }

      $conn->query("SET SESSION sql_mode=''");

      // rating may be NULL; easiest: separate statement when rating is NULL
      $st = $conn->prepare("
        INSERT INTO cf_problems (contest_id, problem_index, title, rating, solved_count)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          title = VALUES(title),
          rating = VALUES(rating),
          solved_count = VALUES(solved_count)
      ");
      if (!$st) {
        $result = ["ok" => false, "msg" => "DB prepare error: " . $conn->error];
      } else {
        $stNull = $conn->prepare("
          INSERT INTO cf_problems (contest_id, problem_index, title, rating, solved_count)
          VALUES (?, ?, ?, NULL, ?)
          ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            rating = NULL,
            solved_count = VALUES(solved_count)
        ");
        if (!$stNull) {
          $st->close();
          $result = ["ok" => false, "msg" => "DB prepare error: " . $conn->error];
        } else {
          $updated = 0;
          foreach ($problems as $p) {
            $contestId = (int)($p["contestId"] ?? 0);
            $index     = (string)($p["index"] ?? "");
            $title     = (string)($p["name"] ?? "");
            $rating    = isset($p["rating"]) ? (int)$p["rating"] : null;

            if ($contestId <= 0 || $index === "" || $title === "") continue;
            $key = $contestId . "_" . $index;
            $solved = (int)($solvedMap[$key] ?? 0);

            if ($rating === null) {
              $stNull->bind_param("issi", $contestId, $index, $title, $solved);
              $stNull->execute();
            } else {
              $st->bind_param("issii", $contestId, $index, $title, $rating, $solved);
              $st->execute();
            }
            $updated++;
          }
          $stNull->close();
          $st->close();
          $result = ["ok" => true, "msg" => "Codeforces problems synced successfully.", "count" => $updated];

          // ✅ persist last sync info
          setting_set($conn, "cf_last_sync_at", date("Y-m-d H:i:s"));
          setting_set($conn, "cf_last_sync_rows", (string)$updated);
          setting_set($conn, "cf_last_sync_msg", "OK");
        }
      }
    }
  }
}

// If sync failed, persist the failure message too (but keep last_sync_at as is)
if (!empty($result) && empty($result["ok"])) {
  setting_set($conn, "cf_last_sync_msg", (string)($result["msg"] ?? "FAILED"));
}

// Refresh cached values for display
$last_sync_at   = setting_get($conn, "cf_last_sync_at", $last_sync_at ?? "");
$last_sync_rows = setting_get($conn, "cf_last_sync_rows", $last_sync_rows ?? "");
$last_sync_msg  = setting_get($conn, "cf_last_sync_msg", $last_sync_msg ?? "");

ui_start("Codeforces Sync", "Admin Panel");
ui_top_actions([
  ["Dashboard", "/admin/dashboard.php"],
]);

?>

<style>
.muted{opacity:.78;}
.kpi{display:flex;gap:12px;align-items:center;justify-content:space-between;border:1px solid rgba(255,255,255,.12);background:rgba(10,15,25,.20);padding:12px 14px;border-radius:16px;}
.kpi .big{font-weight:900;font-size:20px;}
</style>

<div class="card">
  <h3 style="margin-bottom:6px;">Sync Codeforces Problems</h3>

  <div style="height:12px;"></div>

  <div class="kpi">
    <div>
      <div class="muted">Last Sync</div>
      <div style="font-weight:900;">
        <?= $last_sync_at !== "" ? e($last_sync_at) : "-" ?>
      </div>
      <?php if ((string)$last_sync_msg !== ""): ?>
        <div class="muted" style="margin-top:4px;">Status: <?= e((string)$last_sync_msg) ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;">
      <div class="muted">Updated rows</div>
      <div style="font-weight:900; font-size:18px;"><?= $last_sync_rows !== "" ? e((string)$last_sync_rows) : "-" ?></div>
    </div>
  </div>

  <div style="height:12px;"></div>

  <?php if (!$has_cf): ?>
    <div class="alert err">Missing table <b>cf_problems</b>. Create the table before syncing.</div>
  <?php endif; ?>

  <?php if ($result): ?>
    <?php if (!empty($result["ok"])): ?>
      <div class="alert ok">
        <?= e((string)($result["msg"] ?? "Done")) ?>
        <?php if (isset($result["count"])): ?>
          <div class="muted" style="margin-top:6px;">Updated rows: <b><?= (int)$result["count"] ?></b></div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="alert err"><?= e((string)($result["msg"] ?? "Failed")) ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="kpi" style="margin-top:12px;">
    <div>
      <div class="muted">Current stored problems</div>
      <div class="big">
        <?php
          $cnt = 0;
          if ($has_cf) {
            $r = $conn->query("SELECT COUNT(*) c FROM cf_problems");
            $cnt = (int)($r ? ($r->fetch_assoc()["c"] ?? 0) : 0);
          }
          echo (int)$cnt;
        ?>
      </div>
    </div>
    <form method="POST" style="margin:0;">
      <button class="btn-primary" type="submit" <?= $has_cf?"":"disabled" ?>>Run Sync Now</button>
    </form>
  </div>

  <div style="height:12px;"></div>
  <a class="badge" href="/uiu_brainnext/admin/dashboard.php">Back to Dashboard</a>
</div>

<?php ui_end(); ?>
