<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db_meta.php";
require_once __DIR__ . "/../includes/db.php"; // <-- MAIN DB (for actor username)

if (session_status() === PHP_SESSION_NONE) session_start();

ui_start("Logs", "Admin Panel");
ui_top_actions([
  ["Dashboard", "/admin/dashboard.php"],
  ["Manage Sections", "/admin/sections_manage.php"],
  ["Enroll Students", "/admin/enrollments_manage.php"],
]);

if (!$conn_meta) {
  echo '<div class="card">
          <h3>Meta DB not connected</h3>
          <div class="muted">Please create <b>uiu_brainnext_meta</b> and check <b>includes/db_meta.php</b>.</div>
        </div>';
  ui_end();
  exit;
}

/* ---------------- helpers ---------------- */
function clamp_int($v, $min, $max) {
  $n = (int)$v;
  if ($n < $min) return $min;
  if ($n > $max) return $max;
  return $n;
}
function stmt_bind(mysqli_stmt $stmt, string $types, array &$params): void {
  if ($types === "") return;
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => $v) $bind[] = &$params[$k];
  call_user_func_array([$stmt, "bind_param"], $bind);
}
function url_with(array $overrides): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) $q[$k] = $v;
  return "/uiu_brainnext/admin/meta_logs.php?" . http_build_query($q);
}
function page_window(int $page, int $pages, int $radius = 2): array {
  if ($pages <= 1) return [1];
  $out = [];

  $left = max(1, $page - $radius);
  $right = min($pages, $page + $radius);

  $out[] = 1;
  if ($left > 2) $out[] = "...";

  for ($i = $left; $i <= $right; $i++) {
    if ($i !== 1 && $i !== $pages) $out[] = $i;
  }

  if ($right < $pages - 1) $out[] = "...";
  if ($pages !== 1) $out[] = $pages;

  $final = [];
  foreach ($out as $x) {
    if (!in_array($x, $final, true)) $final[] = $x;
  }
  return $final;
}

/* which sheet to open after reload */
$tab = trim($_GET["tab"] ?? ""); // "audit" or "login"

/* ---------------- date range ---------------- */
$from = trim($_GET["from"] ?? "");
$to   = trim($_GET["to"] ?? "");
if ($from === "" && $to === "") {
  $from = date("Y-m-d", strtotime("-7 days"));
  $to   = date("Y-m-d");
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date("Y-m-d", strtotime("-7 days"));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date("Y-m-d");

/* ---------------- summary ---------------- */
$summary = ["logins_24h"=>0,"failed_24h"=>0,"audits_24h"=>0,"top_actions"=>[],"top_failed_ips"=>[]];

$st = $conn_meta->prepare("SELECT COUNT(*) c FROM login_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR)");
$st->execute();
$summary["logins_24h"] = (int)($st->get_result()->fetch_assoc()["c"] ?? 0);

$st = $conn_meta->prepare("SELECT COUNT(*) c FROM login_logs WHERE success=0 AND created_at >= (NOW() - INTERVAL 24 HOUR)");
$st->execute();
$summary["failed_24h"] = (int)($st->get_result()->fetch_assoc()["c"] ?? 0);

$st = $conn_meta->prepare("SELECT COUNT(*) c FROM audit_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR)");
$st->execute();
$summary["audits_24h"] = (int)($st->get_result()->fetch_assoc()["c"] ?? 0);

$st = $conn_meta->prepare("
  SELECT action, COUNT(*) cnt
  FROM audit_logs
  WHERE created_at >= (NOW() - INTERVAL 7 DAY)
  GROUP BY action
  ORDER BY cnt DESC
  LIMIT 5
");
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $summary["top_actions"][] = $row;

$st = $conn_meta->prepare("
  SELECT ip, COUNT(*) cnt
  FROM login_logs
  WHERE success=0 AND created_at >= (NOW() - INTERVAL 7 DAY)
    AND ip IS NOT NULL AND ip <> ''
  GROUP BY ip
  ORDER BY cnt DESC
  LIMIT 5
");
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $summary["top_failed_ips"][] = $row;

/* Dropdown values */
$actionList = [];
$st2 = $conn_meta->prepare("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
$st2->execute();
$r2 = $st2->get_result();
while ($x = $r2->fetch_assoc()) $actionList[] = $x["action"];

$tableList = [];
$st3 = $conn_meta->prepare("SELECT DISTINCT entity_table FROM audit_logs ORDER BY entity_table ASC");
$st3->execute();
$r3 = $st3->get_result();
while ($x = $r3->fetch_assoc()) $tableList[] = $x["entity_table"];

/* ---------------- AUDIT filters ---------------- */
$a_role   = trim($_GET["a_role"] ?? "");
$a_action = trim($_GET["a_action"] ?? "");
$a_table  = trim($_GET["a_table"] ?? "");
$a_actor  = trim($_GET["a_actor"] ?? "");
$a_user   = trim($_GET["a_user"] ?? "");   // <-- NEW: username filter
$a_kw     = trim($_GET["a_kw"] ?? "");

$a_page = clamp_int($_GET["a_page"] ?? 1, 1, 999999);
$a_per  = clamp_int($_GET["a_per"] ?? 10, 5, 50);

$a_where  = " WHERE created_at >= ? AND created_at < (DATE_ADD(?, INTERVAL 1 DAY)) ";
$a_params = [$from, $to];
$a_types  = "ss";

if ($a_role !== "")   { $a_where .= " AND actor_role = ? "; $a_params[] = $a_role; $a_types .= "s"; }
if ($a_action !== "") { $a_where .= " AND action = ? ";     $a_params[] = $a_action; $a_types .= "s"; }
if ($a_table !== "")  { $a_where .= " AND entity_table = ? "; $a_params[] = $a_table; $a_types .= "s"; }
if ($a_actor !== "" && ctype_digit($a_actor)) { $a_where .= " AND actor_user_id = ? "; $a_params[] = (int)$a_actor; $a_types .= "i"; }

if ($a_kw !== "") {
  $a_where .= " AND (action LIKE ? OR entity_table LIKE ? OR ip LIKE ? OR user_agent LIKE ? OR CAST(details_json AS CHAR) LIKE ?) ";
  $like = "%".$a_kw."%";
  array_push($a_params, $like, $like, $like, $like, $like);
  $a_types .= "sssss";
}

/* NEW: actor username filter -> find matching user IDs in MAIN DB */
if ($a_user !== "" && isset($conn) && $conn instanceof mysqli) {
  $like = "%".$a_user."%";
  $ids = [];
  $stU = $conn->prepare("SELECT id FROM users WHERE username LIKE ? OR full_name LIKE ? LIMIT 500");
  if ($stU) {
    $stU->bind_param("ss", $like, $like);
    $stU->execute();
    $rs = $stU->get_result();
    while ($rs && ($rr = $rs->fetch_assoc())) $ids[] = (int)$rr["id"];
  }

  if (empty($ids)) {
    $a_where .= " AND 1=0 ";
  } else {
    $in = implode(",", array_fill(0, count($ids), "?"));
    $a_where .= " AND actor_user_id IN ($in) ";
    foreach ($ids as $idv) { $a_params[] = $idv; $a_types .= "i"; }
  }
}

/* count */
$st = $conn_meta->prepare("SELECT COUNT(*) c FROM audit_logs ".$a_where);
stmt_bind($st, $a_types, $a_params);
$st->execute();
$a_total = (int)($st->get_result()->fetch_assoc()["c"] ?? 0);

$a_pages = max(1, (int)ceil(($a_total ?: 0) / $a_per));
if ($a_page > $a_pages) $a_page = $a_pages;
$a_offset = ($a_page - 1) * $a_per;

/* fetch */
$a_sql = "SELECT id, actor_user_id, actor_role, action, entity_table, entity_id, details_json, ip, user_agent, created_at
          FROM audit_logs ".$a_where." ORDER BY id DESC LIMIT ? OFFSET ?";

$a_params2 = $a_params;
$a_types2  = $a_types . "ii";
$a_params2[] = $a_per;
$a_params2[] = $a_offset;

$st = $conn_meta->prepare($a_sql);
stmt_bind($st, $a_types2, $a_params2);
$st->execute();
$a_res = $st->get_result();

$a_data = [];
while ($a_res && ($rw = $a_res->fetch_assoc())) $a_data[] = $rw;

$a_nav = page_window($a_page, $a_pages, 2);

/* Build actor username map (MAIN DB) */
$actor_map = []; // actor_user_id => ["username"=>..., "name"=>...]
if (!empty($a_data) && isset($conn) && $conn instanceof mysqli) {
  $actor_ids = [];
  foreach ($a_data as $rw) {
    $aid = (int)($rw["actor_user_id"] ?? 0);
    if ($aid > 0) $actor_ids[$aid] = true;
  }
  $actor_ids = array_keys($actor_ids);

  if (!empty($actor_ids)) {
    $in = implode(",", array_fill(0, count($actor_ids), "?"));
    $typesU = str_repeat("i", count($actor_ids));
    $sqlU = "SELECT id, username, full_name FROM users WHERE id IN ($in)";
    $stUU = $conn->prepare($sqlU);
    if ($stUU) {
      $bind = [];
      $bind[] = $typesU;
      foreach ($actor_ids as $k => $v) $bind[] = &$actor_ids[$k];
      call_user_func_array([$stUU, "bind_param"], $bind);

      $stUU->execute();
      $rs = $stUU->get_result();
      while ($rs && ($urow = $rs->fetch_assoc())) {
        $id = (int)$urow["id"];
        $actor_map[$id] = [
          "username" => (string)($urow["username"] ?? ""),
          "name" => (string)($urow["full_name"] ?? $urow["username"] ?? ""),
        ];
      }
    }
  }
}

/* ---------------- LOGIN filters ---------------- */
$l_success = trim($_GET["l_success"] ?? "");
$l_email   = trim($_GET["l_email"] ?? "");
$l_user_id = trim($_GET["l_user_id"] ?? "");
$l_ip      = trim($_GET["l_ip"] ?? "");

$l_page = clamp_int($_GET["l_page"] ?? 1, 1, 999999);
$l_per  = clamp_int($_GET["l_per"] ?? 10, 5, 50);

$l_where = " WHERE created_at >= ? AND created_at < (DATE_ADD(?, INTERVAL 1 DAY)) ";
$l_params = [$from, $to];
$l_types  = "ss";

if ($l_success !== "" && in_array($l_success, ["0","1"], true)) { $l_where .= " AND success = ? "; $l_params[] = (int)$l_success; $l_types.="i"; }
if ($l_email !== "") { $l_where .= " AND email LIKE ? "; $like="%".$l_email."%"; $l_params[]=$like; $l_types.="s"; }
if ($l_user_id !== "" && ctype_digit($l_user_id)) { $l_where .= " AND user_id = ? "; $l_params[]=(int)$l_user_id; $l_types.="i"; }
if ($l_ip !== "") { $l_where .= " AND ip LIKE ? "; $like="%".$l_ip."%"; $l_params[]=$like; $l_types.="s"; }

$st = $conn_meta->prepare("SELECT COUNT(*) c FROM login_logs ".$l_where);
stmt_bind($st, $l_types, $l_params);
$st->execute();
$l_total = (int)($st->get_result()->fetch_assoc()["c"] ?? 0);

$l_pages = max(1, (int)ceil(($l_total ?: 0) / $l_per));
if ($l_page > $l_pages) $l_page = $l_pages;
$l_offset = ($l_page - 1) * $l_per;

$l_sql = "SELECT id, user_id, email, success, ip, user_agent, created_at
          FROM login_logs ".$l_where." ORDER BY id DESC LIMIT ? OFFSET ?";

$l_params2 = $l_params;
$l_types2  = $l_types . "ii";
$l_params2[] = $l_per;
$l_params2[] = $l_offset;

$st = $conn_meta->prepare($l_sql);
stmt_bind($st, $l_types2, $l_params2);
$st->execute();
$l_rows = $st->get_result();

$l_nav = page_window($l_page, $l_pages, 2);
?>

<style>
.smallmono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px;}
.pill-ok{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:900;border:1px solid rgba(0,180,90,.35);background:rgba(0,180,90,.12);}
.pill-bad{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:900;border:1px solid rgba(255,80,80,.35);background:rgba(255,80,80,.10);}

.filters{
  display:grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap:10px;
  align-items:end;
}
@media (max-width: 1200px){ .filters{grid-template-columns: repeat(6, minmax(0,1fr));} }
@media (max-width: 720px){ .filters{grid-template-columns: repeat(2, minmax(0,1fr));} }

.fcol-2{grid-column: span 2;}
.fcol-3{grid-column: span 3;}
.fcol-4{grid-column: span 4;}
.fcol-6{grid-column: span 6;}
.fcol-12{grid-column: span 12;}
.filters input, .filters select{ width:100%; box-sizing:border-box; }

/* ========== BOTTOM SHEET MODAL ========== */
.sheet-overlay{
  position:fixed; inset:0;
  background: rgba(0,0,0,.72);
  backdrop-filter: blur(2px);
  display:none;
  align-items:flex-end;
  justify-content:center;
  padding: 0;
  z-index:9999;
}
.sheet{
  width: 100%;
  max-height: 92vh;
  overflow: auto;

  border-top-left-radius: 22px;
  border-top-right-radius: 22px;

  border: 1px solid rgba(255,255,255,.10);
  border-bottom: none;

  background: rgba(10, 16, 26, .94);
  backdrop-filter: blur(12px);

  box-shadow:
    0 -18px 60px rgba(0,0,0,.55),
    inset 0 1px 0 rgba(255,255,255,.06);

  transform: translateY(18px);
  opacity: 0;
  transition: transform .18s ease-out, opacity .18s ease-out;
}
.sheet.show{ transform: translateY(0); opacity: 1; }

.sheet-inner{
  width: min(1240px, 100%);
  margin: 0 auto;
  padding: 14px 14px 18px 14px;
}
.sheet-header{
  position: sticky;
  top: 0;
  z-index: 5;

  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.08);
  background: rgba(10, 16, 26, .86);
  backdrop-filter: blur(10px);

  padding: 12px 12px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom: 12px;
}
.sheet-handle{
  width: 54px;
  height: 6px;
  border-radius: 999px;
  background: rgba(255,255,255,.20);
  margin: 6px auto 10px auto;
}
.sheet .table{ border-radius: 14px; overflow: hidden; }

.pager{
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:10px;
  flex-wrap:wrap;
  margin-top: 10px;
}
.pagebar{
  display:flex;
  align-items:center;
  gap:6px;
  flex-wrap:wrap;
}
.pagebar a, .pagebar span{
  display:inline-block;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.12);
  text-decoration:none;
  font-weight:900;
  font-size:12px;
}
.pagebar .cur{
  background: rgba(255,255,255,.10);
  border-color: rgba(255,255,255,.22);
}
.pagebar .dots{
  border-color: transparent;
  padding: 6px 6px;
  opacity: .7;
}

/* ========== POPUP MODAL (Audit Details) ========== */
.modal-overlay{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.55);
  backdrop-filter: blur(4px);
  display:flex;
  align-items:center;
  justify-content:center;
  z-index:10000;
  padding:18px;
}
.modal-hidden{ display:none; }
.modal-box{
  width:min(980px, 96vw);
  max-height:82vh;
  overflow:auto;
  border-radius:18px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(10,15,25,.92);
  box-shadow:0 20px 80px rgba(0,0,0,.55);
  padding:16px 16px 18px;
}
.modal-head{
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:center;
}
.modal-title{ font-weight:900; }
.modal-close{
  cursor:pointer;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(255,255,255,.06);
  border-radius:12px;
  padding:6px 10px;
  font-weight:900;
}
.modal-pre{
  white-space:pre-wrap;
  margin:12px 0 0;
  font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
  font-size:13px;
  opacity:.95;
}
</style>

<!-- ===================== MAIN PAGE ===================== -->
<div class="card">
  <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start;">
    <div>
      <h3 style="margin-bottom:6px;">System Logs</h3>
      <div class="muted">Security + audit history in <b>uiu_brainnext_meta</b></div>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <div class="card" style="padding:12px 14px;">
        <div class="muted">Logins (24h)</div>
        <div style="font-weight:900; font-size:18px;"><?= (int)$summary["logins_24h"] ?></div>
      </div>
      <div class="card" style="padding:12px 14px;">
        <div class="muted">Failed (24h)</div>
        <div style="font-weight:900; font-size:18px;"><?= (int)$summary["failed_24h"] ?></div>
      </div>
      <div class="card" style="padding:12px 14px;">
        <div class="muted">Audit (24h)</div>
        <div style="font-weight:900; font-size:18px;"><?= (int)$summary["audits_24h"] ?></div>
      </div>
    </div>
  </div>

  <div style="height:12px;"></div>

  <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
    <div class="card" style="padding:12px 14px;">
      <div class="muted" style="margin-bottom:6px;">Top Actions (7 days)</div>
      <?php if (empty($summary["top_actions"])): ?>
        <div class="muted">No data</div>
      <?php else: ?>
        <?php foreach ($summary["top_actions"] as $x): ?>
          <div style="display:flex; justify-content:space-between; gap:10px;">
            <div style="font-weight:900;"><?= e($x["action"]) ?></div>
            <div class="muted"><?= (int)$x["cnt"] ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card" style="padding:12px 14px;">
      <div class="muted" style="margin-bottom:6px;">Top Failed IPs (7 days)</div>
      <?php if (empty($summary["top_failed_ips"])): ?>
        <div class="muted">No data</div>
      <?php else: ?>
        <?php foreach ($summary["top_failed_ips"] as $x): ?>
          <div style="display:flex; justify-content:space-between; gap:10px;">
            <div class="smallmono"><?= e($x["ip"]) ?></div>
            <div class="muted"><?= (int)$x["cnt"] ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div style="height:14px;"></div>

  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <button type="button" class="badge" onclick="openSheet('audit')">Audit Logs</button>
    <button type="button" class="badge" onclick="openSheet('login')">Login Logs</button>
  </div>
</div>

<!-- ===================== AUDIT SHEET ===================== -->
<div class="sheet-overlay" id="sheet-audit">
  <div class="sheet" id="sheet-audit-box">
    <div class="sheet-inner">
      <div class="sheet-handle"></div>

      <div class="sheet-header">
        <div>
          <div style="font-weight:900; font-size:18px;">Audit Logs</div>
          <div class="muted">Track admin/teacher actions (who did what, when).</div>
        </div>
        <button type="button" class="badge" onclick="closeSheet('audit')">Close</button>
      </div>

      <div class="card">
        <form method="GET" class="filters">
          <input type="hidden" name="tab" value="audit">
          <input type="hidden" name="a_page" value="1">

          <div class="fcol-2">
            <label class="label">From</label>
            <input type="date" name="from" value="<?= e($from) ?>">
          </div>
          <div class="fcol-2">
            <label class="label">To</label>
            <input type="date" name="to" value="<?= e($to) ?>">
          </div>

          <div class="fcol-2">
            <label class="label">Role</label>
            <select name="a_role">
              <option value="">All</option>
              <option value="admin" <?= $a_role==="admin"?"selected":"" ?>>admin</option>
              <option value="teacher" <?= $a_role==="teacher"?"selected":"" ?>>teacher</option>
              <option value="student" <?= $a_role==="student"?"selected":"" ?>>student</option>
            </select>
          </div>

          <div class="fcol-3">
            <label class="label">Action</label>
            <select name="a_action">
              <option value="">All</option>
              <?php foreach ($actionList as $a): ?>
                <option value="<?= e($a) ?>" <?= $a_action===$a?"selected":"" ?>><?= e($a) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="fcol-3">
            <label class="label">Entity Table</label>
            <select name="a_table">
              <option value="">All</option>
              <?php foreach ($tableList as $t): ?>
                <option value="<?= e($t) ?>" <?= $a_table===$t?"selected":"" ?>><?= e($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="fcol-2">
            <label class="label">Actor ID</label>
            <input name="a_actor" value="<?= e($a_actor) ?>" placeholder="e.g. 3">
          </div>

          <div class="fcol-3">
            <label class="label">Actor Username</label>
            <input name="a_user" value="<?= e($a_user) ?>" placeholder="username / name">
          </div>

          <div class="fcol-3">
            <label class="label">Keyword</label>
            <input name="a_kw" value="<?= e($a_kw) ?>" placeholder="action / ip / details...">
          </div>

          <div class="fcol-2">
            <label class="label">Per Page</label>
            <select name="a_per">
              <?php foreach ([10,15,20,30,50] as $pp): ?>
                <option value="<?= $pp ?>" <?= ((int)$a_per===$pp)?"selected":"" ?>><?= $pp ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="fcol-2">
            <button class="btn-primary" type="submit" style="width:100%;">Filter Audit</button>
          </div>
        </form>

        <div style="height:10px;"></div>
        <div class="muted" style="font-weight:900;">
          Rows: <?= (int)$a_total ?> • Page <?= (int)$a_page ?> / <?= (int)$a_pages ?>
        </div>
      </div>

      <div style="height:12px;"></div>

      <div class="card">
        <?php if ($a_total === 0): ?>
          <div class="muted">No audit logs found in this filter range.</div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Time</th>
                <th>Actor</th>
                <th>Username</th>
                <th>Action</th>
                <th>Entity</th>
                <th>IP</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($a_data as $row): ?>
                <?php
                  $details_raw = (string)($row["details_json"] ?? "");
                  $entity = e($row["entity_table"])." #".(int)$row["entity_id"];
                  $aid = (int)($row["actor_user_id"] ?? 0);
                  $uname = ($aid > 0 && isset($actor_map[$aid])) ? (string)$actor_map[$aid]["username"] : "";
                ?>
                <tr>
                  <td class="smallmono"><?= e($row["created_at"]) ?></td>
                  <td>
                    <div style="font-weight:900;"><?= e($row["actor_role"]) ?></div>
                    <div class="muted smallmono">ID: <?= (int)$row["actor_user_id"] ?></div>
                  </td>

                  <td class="smallmono" style="font-weight:900;">
                    <?= $uname !== "" ? e($uname) : '<span class="muted">-</span>' ?>
                  </td>

                  <td style="font-weight:900;"><?= e($row["action"]) ?></td>
                  <td class="smallmono"><?= $entity ?></td>
                  <td class="smallmono"><?= e($row["ip"] ?? "") ?></td>
                  <td>
                    <?php if (trim($details_raw) !== ""): ?>
                      <button
                        class="badge"
                        type="button"
                        onclick="openAuditModal(this)"
                        data-details-b64="<?= e(base64_encode($details_raw)) ?>"
                      >View</button>
                    <?php else: ?>
                      <span class="muted">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="pager">
            <?php if ($a_page > 1): ?>
              <a class="badge" href="<?= e(url_with(["tab"=>"audit","a_page"=>$a_page-1])) ?>">Prev</a>
            <?php endif; ?>

            <div class="pagebar">
              <?php foreach ($a_nav as $p): ?>
                <?php if ($p === "..."): ?>
                  <span class="dots">...</span>
                <?php else: ?>
                  <?php if ((int)$p === (int)$a_page): ?>
                    <span class="cur"><?= (int)$p ?></span>
                  <?php else: ?>
                    <a href="<?= e(url_with(["tab"=>"audit","a_page"=>$p])) ?>"><?= (int)$p ?></a>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>

            <?php if ($a_page < $a_pages): ?>
              <a class="badge" href="<?= e(url_with(["tab"=>"audit","a_page"=>$a_page+1])) ?>">Next</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<!-- ===================== LOGIN SHEET ===================== -->
<div class="sheet-overlay" id="sheet-login">
  <div class="sheet" id="sheet-login-box">
    <div class="sheet-inner">
      <div class="sheet-handle"></div>

      <div class="sheet-header">
        <div>
          <div style="font-weight:900; font-size:18px;">Login Logs</div>
          <div class="muted">See successful/failed logins (security + monitoring).</div>
        </div>
        <button type="button" class="badge" onclick="closeSheet('login')">Close</button>
      </div>

      <div class="card">
        <form method="GET" class="filters">
          <input type="hidden" name="tab" value="login">
          <input type="hidden" name="l_page" value="1">

          <div class="fcol-2">
            <label class="label">From</label>
            <input type="date" name="from" value="<?= e($from) ?>">
          </div>
          <div class="fcol-2">
            <label class="label">To</label>
            <input type="date" name="to" value="<?= e($to) ?>">
          </div>

          <div class="fcol-2">
            <label class="label">Success</label>
            <select name="l_success">
              <option value="" <?= $l_success===""?"selected":"" ?>>All</option>
              <option value="1" <?= $l_success==="1"?"selected":"" ?>>Success</option>
              <option value="0" <?= $l_success==="0"?"selected":"" ?>>Failed</option>
            </select>
          </div>

          <div class="fcol-3">
            <label class="label">Email / Username</label>
            <input name="l_email" value="<?= e($l_email) ?>" placeholder="search...">
          </div>

          <div class="fcol-2">
            <label class="label">User ID</label>
            <input name="l_user_id" value="<?= e($l_user_id) ?>" placeholder="e.g. 5">
          </div>

          <div class="fcol-3">
            <label class="label">IP contains</label>
            <input name="l_ip" value="<?= e($l_ip) ?>" placeholder="e.g. 192.168">
          </div>

          <div class="fcol-2">
            <label class="label">Per Page</label>
            <select name="l_per">
              <?php foreach ([10,15,20,30,50] as $pp): ?>
                <option value="<?= $pp ?>" <?= ((int)$l_per===$pp)?"selected":"" ?>><?= $pp ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="fcol-2">
            <button class="btn-primary" type="submit" style="width:100%;">Filter Login</button>
          </div>
        </form>

        <div style="height:10px;"></div>
        <div class="muted" style="font-weight:900;">
          Rows: <?= (int)$l_total ?> • Page <?= (int)$l_page ?> / <?= (int)$l_pages ?>
        </div>
      </div>

      <div style="height:12px;"></div>

      <div class="card">
        <?php if ($l_total === 0): ?>
          <div class="muted">No login logs found in this filter range.</div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Time</th>
                <th>User</th>
                <th>Status</th>
                <th>IP</th>
                <th>User Agent</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($row = $l_rows->fetch_assoc()): ?>
                <tr>
                  <td class="smallmono"><?= e($row["created_at"]) ?></td>
                  <td>
                    <div style="font-weight:900;"><?= e($row["email"] ?? "") ?></div>
                    <div class="muted smallmono">ID: <?= $row["user_id"]===null ? "-" : (int)$row["user_id"] ?></div>
                  </td>
                  <td>
                    <?php if ((int)$row["success"] === 1): ?>
                      <span class="pill-ok">Success</span>
                    <?php else: ?>
                      <span class="pill-bad">Failed</span>
                    <?php endif; ?>
                  </td>
                  <td class="smallmono"><?= e($row["ip"] ?? "") ?></td>
                  <td class="muted" style="max-width:520px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    <?= e($row["user_agent"] ?? "") ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>

          <div class="pager">
            <?php if ($l_page > 1): ?>
              <a class="badge" href="<?= e(url_with(["tab"=>"login","l_page"=>$l_page-1])) ?>">Prev</a>
            <?php endif; ?>

            <div class="pagebar">
              <?php foreach ($l_nav as $p): ?>
                <?php if ($p === "..."): ?>
                  <span class="dots">...</span>
                <?php else: ?>
                  <?php if ((int)$p === (int)$l_page): ?>
                    <span class="cur"><?= (int)$p ?></span>
                  <?php else: ?>
                    <a href="<?= e(url_with(["tab"=>"login","l_page"=>$p])) ?>"><?= (int)$p ?></a>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>

            <?php if ($l_page < $l_pages): ?>
              <a class="badge" href="<?= e(url_with(["tab"=>"login","l_page"=>$l_page+1])) ?>">Next</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<!-- ========== POPUP MODAL (for Audit Details) ========== -->
<div id="auditModalOverlay" class="modal-overlay modal-hidden" onclick="closeAuditModalFromOverlay()">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="modal-head">
      <div class="modal-title">Audit Details</div>
      <button class="modal-close" type="button" onclick="closeAuditModal()">X</button>
    </div>
    <div style="height:10px;"></div>
    <pre id="auditModalBody" class="modal-pre"></pre>
  </div>
</div>

<script>
function openSheet(which){
  const overlay = document.getElementById(which === 'audit' ? 'sheet-audit' : 'sheet-login');
  const box = document.getElementById(which === 'audit' ? 'sheet-audit-box' : 'sheet-login-box');
  if(!overlay || !box) return;

  overlay.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  requestAnimationFrame(()=> box.classList.add('show'));
}
function closeSheet(which){
  const overlay = document.getElementById(which === 'audit' ? 'sheet-audit' : 'sheet-login');
  const box = document.getElementById(which === 'audit' ? 'sheet-audit-box' : 'sheet-login-box');
  if(!overlay || !box) return;

  box.classList.remove('show');
  setTimeout(()=>{
    overlay.style.display = 'none';
    document.body.style.overflow = '';
  }, 180);
}

// click outside closes
document.getElementById('sheet-audit')?.addEventListener('click', (e)=>{ if(e.target.id==='sheet-audit') closeSheet('audit'); });
document.getElementById('sheet-login')?.addEventListener('click', (e)=>{ if(e.target.id==='sheet-login') closeSheet('login'); });

// ESC closes sheets + modal
document.addEventListener('keydown', (e)=>{
  if(e.key==='Escape'){
    closeSheet('audit');
    closeSheet('login');
    closeAuditModal();
  }
});

// auto-open after filtering / paging
<?php if ($tab === "audit"): ?>openSheet('audit');<?php endif; ?>
<?php if ($tab === "login"): ?>openSheet('login');<?php endif; ?>

/* ===== base64 decode safe (supports unicode) ===== */
function b64ToUtf8(b64){
  try{
    const bin = atob(b64);
    const bytes = new Uint8Array([...bin].map(ch => ch.charCodeAt(0)));
    return new TextDecoder("utf-8").decode(bytes);
  }catch(e){
    try { return atob(b64); } catch(ex){ return ""; }
  }
}

/* ========== Audit popup modal ========== */
function openAuditModal(btn){
  const b64 = btn.getAttribute("data-details-b64") || "";
  const raw = b64 ? b64ToUtf8(b64) : "";

  let pretty = raw;
  try{
    const obj = JSON.parse(raw);
    pretty = JSON.stringify(obj, null, 2);

    // REMOVE OUTER { } BRACKETS (your request)
    // If pretty looks like:
    // {
    //   "a": 1
    // }
    // make it:
    // "a": 1
    if (pretty.startsWith("{") && pretty.endsWith("}")) {
      pretty = pretty.trim();
      // remove first { and last }
      pretty = pretty.substring(1, pretty.length - 1).trim();

      // remove one indent level (2 spaces) from each line
      pretty = pretty.split("\n").map(line => line.startsWith("  ") ? line.substring(2) : line).join("\n");
    }
  }catch(e){
    // not JSON, keep raw
  }

  document.getElementById("auditModalBody").textContent = pretty || "(no details)";
  document.getElementById("auditModalOverlay").classList.remove("modal-hidden");
}
function closeAuditModal(){
  document.getElementById("auditModalOverlay").classList.add("modal-hidden");
}
function closeAuditModalFromOverlay(){
  closeAuditModal();
}
</script>

<?php ui_end(); ?>








