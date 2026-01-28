<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

// meta DB connector (you already have this in project)
$metaFile = __DIR__ . "/../includes/ai_meta_store.php";
if (file_exists($metaFile)) require_once $metaFile;

// Prefer meta DB if available
$AUDIT_CONN = null;
if (function_exists("ai_meta_conn")) {
  $AUDIT_CONN = ai_meta_conn();
}
if (!$AUDIT_CONN) $AUDIT_CONN = $conn;

/* ---------- DB helpers ---------- */
function db_current_name2(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db");
  $row = $r ? $r->fetch_assoc() : null;
  return (string)($row["db"] ?? "");
}

function db_has_table2(mysqli $conn, string $table): bool {
  $db = db_current_name2($conn);
  if ($db === "") return false;

  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME = ?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("ss", $db, $table);
  $st->execute();
  $res = $st->get_result();
  return (bool)$res->fetch_row();
}

/* ---------- Page ---------- */
ui_start("AI Audit Trail", "Admin Panel");

if (!db_has_table2($AUDIT_CONN, "ai_audit_logs")) {
  echo '<div class="card">';
  echo '<h3 style="margin-bottom:6px;">AI Audit Trail</h3>';
  echo '<div class="alert err">Table <b>ai_audit_logs</b> not found in this DB: <b>' . e(db_current_name2($AUDIT_CONN)) . '</b></div>';
  echo '<div class="muted">You created it in <b>uiu_brainnext_meta</b>. Make sure this page is using meta DB connection.</div>';
  echo '</div>';
  ui_end();
  exit;
}

$q = trim((string)($_GET["q"] ?? ""));
$action = trim((string)($_GET["action"] ?? ""));
$status = trim((string)($_GET["status"] ?? ""));
$limit = (int)($_GET["limit"] ?? 100);
if ($limit < 20) $limit = 20;
if ($limit > 500) $limit = 500;

$where = "1=1";
$params = [];
$types = "";

if ($q !== "") {
  $where .= " AND (
    model LIKE CONCAT('%',?,'%')
    OR prompt_sha256 LIKE CONCAT('%',?,'%')
    OR response_sha256 LIKE CONCAT('%',?,'%')
    OR ip LIKE CONCAT('%',?,'%')
    OR user_agent LIKE CONCAT('%',?,'%')
    OR title LIKE CONCAT('%',?,'%')
  )";
  $types .= "ssssss";
  $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
}

if ($action !== "") {
  $where .= " AND action = ?";
  $types .= "s";
  $params[] = $action;
}

if ($status !== "") {
  $where .= " AND status = ?";
  $types .= "s";
  $params[] = $status;
}

$sql = "
  SELECT
    id, created_at, user_id, role, action, model,
    target_type, target_id, title, status,
    latency_ms, token_est, ip, user_agent,
    prompt_sha256, response_sha256,
    response_preview, error_text
  FROM ai_audit_logs
  WHERE $where
  ORDER BY id DESC
  LIMIT $limit
";

$st = $AUDIT_CONN->prepare($sql);
if (!$st) {
  echo '<div class="alert err">SQL prepare failed: ' . e($AUDIT_CONN->error) . '</div>';
  ui_end();
  exit;
}

if ($types !== "") {
  $bind = [];
  $bind[] = $types;
  for ($i = 0; $i < count($params); $i++) $bind[] = &$params[$i];
  call_user_func_array([$st, "bind_param"], $bind);
}

$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<style>
.smallmono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px;}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:9999;}
.modal-box{width:min(980px,92vw);max-height:82vh;overflow:hidden;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(10,18,30,.96);box-shadow:0 20px 80px rgba(0,0,0,.6);}
.modal-head{display:flex;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08);align-items:center;}
.modal-title{font-weight:900;}
.modal-close{cursor:pointer;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#fff;padding:6px 10px;border-radius:999px;}
.modal-body{padding:14px 16px;overflow:auto;max-height:calc(82vh - 60px);}
.modal-pre{margin:0;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px;line-height:1.45;}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:900;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);}
.pill-ok{border-color:rgba(0,180,90,.35);background:rgba(0,180,90,.12);}
.pill-err{border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.10);}
.pill-cache{border-color:rgba(255,180,0,.35);background:rgba(255,180,0,.10);}
</style>

<div class="card">
  <h3 style="margin-bottom:10px;">AI Audit Trail</h3>

  <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
    <input class="input" name="q" placeholder="Search model/hash/ip/title..." value="<?= e($q) ?>" style="min-width:280px;">
    <input class="input" name="action" placeholder="action (hint/feedback/codecheck)" value="<?= e($action) ?>" style="min-width:240px;">
    <select name="status">
      <option value="">All status</option>
      <option value="ok" <?= $status==="ok"?"selected":"" ?>>ok</option>
      <option value="cached" <?= $status==="cached"?"selected":"" ?>>cached</option>
      <option value="error" <?= $status==="error"?"selected":"" ?>>error</option>
    </select>
    <input class="input" type="number" name="limit" value="<?= (int)$limit ?>" min="20" max="500" style="width:110px;">
    <button class="btn-primary" type="submit">Filter</button>
  </form>

  <div class="muted" style="margin-bottom:10px;">
    Reading from DB: <span class="smallmono"><?= e(db_current_name2($AUDIT_CONN)) ?></span>
  </div>

  <?php if (!$rows): ?>
    <div class="muted">No audit logs yet.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Time</th>
            <th>User</th>
            <th>Role</th>
            <th>Action</th>
            <th>Status</th>
            <th>Latency</th>
            <th>Model</th>
            <th>Target</th>
            <th>IP</th>
            <th>Title</th>
            <th>View</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $stt = (string)($r["status"] ?? "");
            $pill = "pill";
            if ($stt === "ok") $pill .= " pill-ok";
            elseif ($stt === "cached") $pill .= " pill-cache";
            elseif ($stt === "error") $pill .= " pill-err";

            $viewPayload = [
              "id" => $r["id"] ?? "",
              "created_at" => $r["created_at"] ?? "",
              "user_id" => $r["user_id"] ?? "",
              "role" => $r["role"] ?? "",
              "action" => $r["action"] ?? "",
              "model" => $r["model"] ?? "",
              "target_type" => $r["target_type"] ?? "",
              "target_id" => $r["target_id"] ?? "",
              "status" => $r["status"] ?? "",
              "latency_ms" => $r["latency_ms"] ?? "",
              "token_est" => $r["token_est"] ?? "",
              "ip" => $r["ip"] ?? "",
              "user_agent" => $r["user_agent"] ?? "",
              "title" => $r["title"] ?? "",
              "prompt_sha256" => $r["prompt_sha256"] ?? "",
              "response_sha256" => $r["response_sha256"] ?? "",
              "response_preview" => $r["response_preview"] ?? "",
              "error_text" => $r["error_text"] ?? "",
            ];
            $viewText =
              "ID: " . $viewPayload["id"] . "\n" .
              "Time: " . $viewPayload["created_at"] . "\n" .
              "User: " . $viewPayload["user_id"] . " (" . $viewPayload["role"] . ")\n" .
              "Action: " . $viewPayload["action"] . "\n" .
              "Model: " . $viewPayload["model"] . "\n" .
              "Target: " . $viewPayload["target_type"] . "#" . $viewPayload["target_id"] . "\n" .
              "Status: " . $viewPayload["status"] . "\n" .
              "Latency: " . $viewPayload["latency_ms"] . " ms\n" .
              "Tokens: " . $viewPayload["token_est"] . "\n" .
              "IP: " . $viewPayload["ip"] . "\n" .
              "User-Agent: " . $viewPayload["user_agent"] . "\n" .
              "Title: " . $viewPayload["title"] . "\n\n" .
              "Prompt SHA256:\n" . $viewPayload["prompt_sha256"] . "\n\n" .
              "Response SHA256:\n" . ($viewPayload["response_sha256"] ?: "-") . "\n\n" .
              "Response Preview:\n" . ($viewPayload["response_preview"] ?: "-") . "\n\n" .
              "Error Text:\n" . ($viewPayload["error_text"] ?: "-") . "\n";
          ?>
          <tr>
            <td><?= (int)$r["id"] ?></td>
            <td class="smallmono"><?= e((string)$r["created_at"]) ?></td>
            <td><?= e((string)($r["user_id"] ?? "")) ?></td>
            <td><?= e((string)($r["role"] ?? "")) ?></td>
            <td><?= e((string)($r["action"] ?? "")) ?></td>
            <td><span class="<?= $pill ?>"><?= e($stt) ?></span></td>
            <td><?= e((string)($r["latency_ms"] ?? "")) ?> ms</td>
            <td class="smallmono"><?= e((string)($r["model"] ?? "")) ?></td>
            <td><?= e((string)($r["target_type"] ?? "")) ?>#<?= e((string)($r["target_id"] ?? "")) ?></td>
            <td class="smallmono"><?= e((string)($r["ip"] ?? "")) ?></td>
            <td><?= e((string)($r["title"] ?? "")) ?></td>
            <td>
              <button type="button" class="badge"
                onclick="openModal('Audit Log #<?= (int)$r['id'] ?>', document.getElementById('raw<?= (int)$r['id'] ?>').textContent)">
                View
              </button>
              <pre id="raw<?= (int)$r['id'] ?>" style="display:none;"><?= e($viewText) ?></pre>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Modal -->
<div id="modalBackdrop" class="modal-backdrop" onclick="closeModal()">
  <div class="modal-box" onclick="event.stopPropagation();">
    <div class="modal-head">
      <div id="modalTitle" class="modal-title">Audit</div>
      <button class="modal-close" type="button" onclick="closeModal()">Close</button>
    </div>
    <div class="modal-body">
      <pre id="modalContent" class="modal-pre"></pre>
    </div>
  </div>
</div>

<script>
function openModal(title, content){
  document.getElementById('modalTitle').textContent = title || 'Audit';
  document.getElementById('modalContent').textContent = content || '-';
  document.getElementById('modalBackdrop').style.display = 'flex';
}
function closeModal(){
  document.getElementById('modalBackdrop').style.display = 'none';
}
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape') closeModal();
});
</script>

<?php ui_end(); ?>


