<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------------- helpers ---------------- */
function db_current_name(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db");
  $row = $r ? $r->fetch_assoc() : null;
  return (string)($row["db"] ?? "");
}
function db_has_col(mysqli $conn, string $table, string $col): bool {
  $db = db_current_name($conn);
  if ($db === "") return false;

  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME   = ?
      AND COLUMN_NAME  = ?
    LIMIT 1
  ");
  if (!$st) return false;

  $st->bind_param("sss", $db, $table, $col);
  $st->execute();
  $res = $st->get_result();
  return (bool)$res->fetch_row();
}
function clamp_int($v, $min, $max) {
  $n = (int)$v;
  if ($n < $min) return $min;
  if ($n > $max) return $max;
  return $n;
}
function pill_class(string $v): string {
  $v = strtoupper(trim($v));
  if ($v === "AC") return "pill pill-ac";
  if ($v === "WA") return "pill pill-wa";
  if (in_array($v, ["CE","RE","TLE"], true)) return "pill pill-bad";
  if ($v === "MANUAL") return "pill pill-manual";
  return "pill";
}
function safe_date_or_empty(string $s): string {
  $s = trim($s);
  if ($s === "") return "";
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return "";
  return $s;
}

/* ---------------- schema detection ---------------- */
$user_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($user_id <= 0) redirect("/uiu_brainnext/logout.php");

$sub_user_col = db_has_col($conn, "submissions", "user_id")
  ? "user_id"
  : (db_has_col($conn, "submissions", "student_id") ? "student_id" : "user_id");

$sub_time_col = db_has_col($conn, "submissions", "submitted_at")
  ? "submitted_at"
  : (db_has_col($conn, "submissions", "created_at") ? "created_at" : "submitted_at");

$has_language = db_has_col($conn, "submissions", "language");
$has_status   = db_has_col($conn, "submissions", "status");
$has_verdict  = db_has_col($conn, "submissions", "verdict");
$has_score    = db_has_col($conn, "submissions", "score");
$has_message  = db_has_col($conn, "submissions", "message");
$has_runtime  = db_has_col($conn, "submissions", "runtime_ms");
$has_code     = db_has_col($conn, "submissions", "answer_text");

/* ---------------- filters ---------------- */
$q       = trim((string)($_GET["q"] ?? ""));
$course  = (int)($_GET["course_id"] ?? 0);
$verdict = strtoupper(trim((string)($_GET["verdict"] ?? "")));
$lang    = strtolower(trim((string)($_GET["lang"] ?? "")));
$from    = safe_date_or_empty((string)($_GET["from"] ?? ""));
$to      = safe_date_or_empty((string)($_GET["to"] ?? ""));

$page = clamp_int($_GET["page"] ?? 1, 1, 999999);
$per  = clamp_int($_GET["per"] ?? 15, 5, 50);
$offset = ($page - 1) * $per;

/* ---------------- dropdown courses (only courses you submitted in) ---------------- */
$courses = [];
$stC = $conn->prepare("
  SELECT DISTINCT c.id, c.code, c.title
  FROM submissions s
  JOIN problems p ON p.id = s.problem_id
  JOIN courses c ON c.id = p.course_id
  WHERE s.$sub_user_col = ?
  ORDER BY c.code ASC
");
$stC->bind_param("i", $user_id);
$stC->execute();
$rC = $stC->get_result();
while ($rC && ($row = $rC->fetch_assoc())) $courses[] = $row;

/* ---------------- build WHERE ---------------- */
$where = " WHERE s.$sub_user_col = ? ";
$params = [$user_id];
$types  = "i";

if ($q !== "") {
  $where .= " AND (p.title LIKE ? OR c.code LIKE ?) ";
  $like = "%".$q."%";
  $params[] = $like;
  $params[] = $like;
  $types .= "ss";
}

if ($course > 0) {
  $where .= " AND c.id = ? ";
  $params[] = $course;
  $types .= "i";
}

if ($has_verdict && $verdict !== "" && in_array($verdict, ["AC","WA","CE","RE","TLE","MANUAL"], true)) {
  $where .= " AND UPPER(s.verdict) = ? ";
  $params[] = $verdict;
  $types .= "s";
}

if ($has_language && $lang !== "" && in_array($lang, ["c","cpp"], true)) {
  $where .= " AND LOWER(s.language) = ? ";
  $params[] = $lang;
  $types .= "s";
}

if ($from !== "") {
  $where .= " AND DATE(s.$sub_time_col) >= ? ";
  $params[] = $from;
  $types .= "s";
}
if ($to !== "") {
  $where .= " AND DATE(s.$sub_time_col) <= ? ";
  $params[] = $to;
  $types .= "s";
}

/* ---------------- count ---------------- */
$stCount = $conn->prepare("
  SELECT COUNT(*) c
  FROM submissions s
  JOIN problems p ON p.id = s.problem_id
  JOIN courses c ON c.id = p.course_id
  $where
");
$bind = [];
$bind[] = $types;
foreach ($params as $k => $v) $bind[] = &$params[$k];
call_user_func_array([$stCount, "bind_param"], $bind);

$stCount->execute();
$total = (int)($stCount->get_result()->fetch_assoc()["c"] ?? 0);

/* ---------------- fetch rows ---------------- */
$select = "
  s.id,
  s.problem_id,
  s.$sub_time_col AS t,
  p.title AS problem_title,
  p.difficulty,
  c.code AS course_code,
  c.title AS course_title
";
if ($has_language) $select .= ", s.language";
if ($has_status)   $select .= ", s.status";
if ($has_verdict)  $select .= ", s.verdict";
if ($has_score)    $select .= ", s.score";
if ($has_message)  $select .= ", s.message";
if ($has_runtime)  $select .= ", s.runtime_ms";
if ($has_code)     $select .= ", s.answer_text";

$sql = "
  SELECT $select
  FROM submissions s
  JOIN problems p ON p.id = s.problem_id
  JOIN courses c ON c.id = p.course_id
  $where
  ORDER BY s.id DESC
  LIMIT ? OFFSET ?
";

$params2 = $params;
$types2  = $types . "ii";
$params2[] = $per;
$params2[] = $offset;

$st = $conn->prepare($sql);
$bind2 = [];
$bind2[] = $types2;
foreach ($params2 as $k => $v) $bind2[] = &$params2[$k];
call_user_func_array([$st, "bind_param"], $bind2);

$st->execute();
$res = $st->get_result();

$rows = [];
while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;

/* ---------------- UI ---------------- */
ui_start("My Submissions", "Student Panel");

ui_top_actions([
  ["Dashboard", "/student/dashboard.php"],
  ["Problems", "/student/problems.php"],
  ["Tests", "/student/student_tests.php"],
]);
?>
<style>
.filters{
  display:grid;
  grid-template-columns: repeat(12, minmax(0,1fr));
  gap:10px;
  align-items:end;
}
@media(max-width: 900px){ .filters{grid-template-columns: repeat(6, minmax(0,1fr));} }
@media(max-width: 620px){ .filters{grid-template-columns: repeat(2, minmax(0,1fr));} }
.fcol-4{grid-column: span 4;}
.fcol-3{grid-column: span 3;}
.fcol-2{grid-column: span 2;}
.fcol-12{grid-column: span 12;}
.filters input,.filters select{width:100%; box-sizing:border-box;}

.smallmono{
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
  font-size: 12px;
}
.pill{
  display:inline-block;
  padding:6px 10px;
  border-radius:999px;
  font-weight:900;
  border:1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.06);
}
.pill-ac{border-color: rgba(0,180,90,.35); background: rgba(0,180,90,.12);}
.pill-wa{border-color: rgba(255,180,0,.35); background: rgba(255,180,0,.10);}
.pill-bad{border-color: rgba(255,80,80,.35); background: rgba(255,80,80,.10);}
.pill-manual{border-color: rgba(120,160,255,.35); background: rgba(120,160,255,.10);}

/* -------- solid modal -------- */
.modal-overlay{
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.65);
  backdrop-filter: blur(6px);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 9999;
}
.modal{
  width: min(980px, 98vw);
  max-height: 90vh;
  overflow: hidden;
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(12,18,30,.92);
  box-shadow: 0 18px 70px rgba(0,0,0,.6);
}
.modal-head{
  padding: 14px 16px;
  border-bottom: 1px solid rgba(255,255,255,.08);
  display:flex;
  justify-content: space-between;
  gap:12px;
  align-items: center;
  flex-wrap: wrap;
}
.modal-body{
  padding: 14px 16px;
  overflow: auto;
  max-height: calc(90vh - 62px);
}
.tabbar{
  display:flex;
  gap:10px;
  flex-wrap: wrap;
  margin-top: 10px;
}
.tabbtn{
  cursor:pointer;
  padding: 8px 12px;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.06);
  font-weight: 900;
}
.tabbtn.active{
  border-color: rgba(120,160,255,.40);
  background: rgba(120,160,255,.12);
}
.codebox{
  white-space: pre-wrap;
  word-break: break-word;
  margin: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
  font-size: 13px;
  line-height: 1.45;
}
.muted2{opacity:.85;}
</style>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start;">
    <div>
      <h2 style="margin-bottom:6px;">My Submissions</h2>
    </div>
    <div class="card" style="padding:12px 14px;">
      <div class="muted">Rows</div>
      <div style="font-weight:900; font-size:18px;"><?= (int)$total ?></div>
    </div>
  </div>

  <div style="height:12px;"></div>

  <form method="GET" class="filters">
    <div class="fcol-4">
      <label class="label">Keyword</label>
      <input name="q" value="<?= e($q) ?>" placeholder="problem title / course code">
    </div>

    <div class="fcol-3">
      <label class="label">Course</label>
      <select name="course_id">
        <option value="0">All</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c["id"] ?>" <?= $course===(int)$c["id"]?"selected":"" ?>>
            <?= e($c["code"] . " - " . $c["title"]) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fcol-2">
      <label class="label">Verdict</label>
      <select name="verdict">
        <option value="">All</option>
        <option value="AC" <?= $verdict==="AC"?"selected":"" ?>>AC</option>
        <option value="WA" <?= $verdict==="WA"?"selected":"" ?>>WA</option>
        <option value="CE" <?= $verdict==="CE"?"selected":"" ?>>CE</option>
        <option value="RE" <?= $verdict==="RE"?"selected":"" ?>>RE</option>
        <option value="TLE" <?= $verdict==="TLE"?"selected":"" ?>>TLE</option>
        <option value="MANUAL" <?= $verdict==="MANUAL"?"selected":"" ?>>MANUAL</option>
      </select>
    </div>

    <div class="fcol-2">
      <label class="label">Language</label>
      <select name="lang">
        <option value="">All</option>
        <option value="c" <?= $lang==="c"?"selected":"" ?>>C</option>
        <option value="cpp" <?= $lang==="cpp"?"selected":"" ?>>C++</option>
      </select>
    </div>

    <div class="fcol-2">
      <label class="label">From</label>
      <input type="date" name="from" value="<?= e($from) ?>">
    </div>

    <div class="fcol-2">
      <label class="label">To</label>
      <input type="date" name="to" value="<?= e($to) ?>">
    </div>

    <div class="fcol-2">
      <label class="label">Per page</label>
      <select name="per">
        <?php foreach ([10,15,20,30,50] as $n): ?>
          <option value="<?= $n ?>" <?= $per===$n?"selected":"" ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fcol-12" style="display:flex; gap:10px; flex-wrap:wrap;">
      <button class="btn-primary" type="submit">Filter</button>
      <a class="badge" href="/uiu_brainnext/student/submissions.php">Reset</a>
    </div>
  </form>
</div>

<div style="height:14px;"></div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="muted">No submissions found for this filter.</div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Time</th>
          <th>Problem</th>
          <th>Course</th>
          <th>Verdict</th>
          <th>Lang</th>
          <th>Runtime</th>
          <th>Score</th>
          <th style="text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $sid = (int)$r["id"];
            $v = strtoupper((string)($r["verdict"] ?? ""));
            $pill = pill_class($v);
            $langShow = $has_language ? strtoupper((string)($r["language"] ?? "")) : "";
            $runtimeShow = $has_runtime && $r["runtime_ms"] !== null ? ((int)$r["runtime_ms"] . " ms") : "-";
            $scoreShow = $has_score && $r["score"] !== null ? (string)(int)$r["score"] : "-";

            $msg = $has_message ? (string)($r["message"] ?? "") : "";
            $code = $has_code ? (string)($r["answer_text"] ?? "") : "";
          ?>
          <tr>
            <td class="smallmono"><?= e((string)($r["t"] ?? "")) ?></td>

            <td>
              <div style="font-weight:900;"><?= e((string)$r["problem_title"]) ?></div>
              <div class="muted"><?= e((string)($r["difficulty"] ?? "")) ?></div>
            </td>

            <td>
              <div style="font-weight:900;"><?= e((string)$r["course_code"]) ?></div>
              <div class="muted"><?= e((string)$r["course_title"]) ?></div>
            </td>

            <td>
              <?php if ($has_verdict && $v !== ""): ?>
                <span class="<?= e($pill) ?>"><?= e($v) ?></span>
              <?php else: ?>
                <span class="muted">-</span>
              <?php endif; ?>
            </td>

            <td class="smallmono"><?= e($langShow !== "" ? $langShow : "-") ?></td>
            <td class="smallmono"><?= e($runtimeShow) ?></td>
            <td style="font-weight:900;"><?= e($scoreShow) ?></td>

            <td style="text-align:right; white-space:nowrap;">
              <a class="badge" href="/uiu_brainnext/student/problem_view.php?id=<?= (int)$r["problem_id"] ?>">Open</a>
              <button type="button"
                class="badge"
                style="cursor:pointer;"
                onclick="openSubModal(<?= $sid ?>)">
                View
              </button>

              <!-- Hidden data blocks (safe) -->
              <script type="application/json" id="submeta-<?= $sid ?>">
                <?= json_encode([
                  "id" => $sid,
                  "time" => (string)($r["t"] ?? ""),
                  "problem" => (string)$r["problem_title"],
                  "course" => (string)$r["course_code"],
                  "verdict" => $v,
                  "language" => $langShow,
                  "runtime" => $runtimeShow,
                  "score" => $scoreShow,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
              </script>
              <script type="application/json" id="subcode-<?= $sid ?>">
                <?= json_encode($code, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
              </script>
              <script type="application/json" id="submsg-<?= $sid ?>">
                <?= json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
              </script>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php
      $total_pages = (int)ceil(max(1, $total) / $per);
      $qs = $_GET;
    ?>
    <div style="height:12px;"></div>
    <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
      <?php if ($page > 1): ?>
        <?php $qs["page"] = $page - 1; ?>
        <a class="badge" href="/uiu_brainnext/student/submissions.php?<?= http_build_query($qs) ?>">← Prev</a>
      <?php endif; ?>
      <span class="muted" style="padding:7px 10px;">Page <?= (int)$page ?> / <?= (int)$total_pages ?></span>
      <?php if ($page < $total_pages): ?>
        <?php $qs["page"] = $page + 1; ?>
        <a class="badge" href="/uiu_brainnext/student/submissions.php?<?= http_build_query($qs) ?>">Next →</a>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>

<!-- Solid Modal -->
<div class="modal-overlay" id="subModalOverlay" onclick="closeSubModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="modal-head">
      <div>
        <div style="font-weight:900;" id="mTitle">Submission</div>
        <div class="muted smallmono muted2" id="mSub">—</div>

        <div class="tabbar">
          <button type="button" class="tabbtn active" id="tabCodeBtn" onclick="setTab('code')">Code</button>
          <button type="button" class="tabbtn" id="tabMsgBtn" onclick="setTab('msg')">Judge Message</button>
        </div>
      </div>

      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <span class="pill" id="mVerdict">-</span>
        <span class="muted smallmono" id="mInfo">-</span>
        <button type="button" class="badge" style="cursor:pointer;" onclick="closeSubModal()">Close</button>
      </div>
    </div>

    <div class="modal-body">
      <div id="tabCode">
        <pre class="codebox" id="mCode">(no code)</pre>
      </div>
      <div id="tabMsg" style="display:none;">
        <pre class="codebox" id="mMsg">(no message)</pre>
      </div>
    </div>
  </div>
</div>

<script>
function getJson(id){
  const el = document.getElementById(id);
  if(!el) return null;
  try { return JSON.parse(el.textContent || "null"); } catch(e){ return null; }
}

function openSubModal(id){
  const meta = getJson("submeta-" + id) || {};
  const code = getJson("subcode-" + id);
  const msg  = getJson("submsg-" + id);

  document.getElementById("mTitle").textContent =
    (meta.problem ? meta.problem : "Submission") + (meta.course ? " • " + meta.course : "");

  document.getElementById("mSub").textContent = meta.time ? ("Time: " + meta.time) : "";

  const v = (meta.verdict || "").toUpperCase();
  const pill = document.getElementById("mVerdict");
  pill.textContent = v !== "" ? v : "-";

  pill.className = "pill";
  if (v === "AC") pill.className += " pill-ac";
  else if (v === "WA") pill.className += " pill-wa";
  else if (["CE","RE","TLE"].includes(v)) pill.className += " pill-bad";
  else if (v === "MANUAL") pill.className += " pill-manual";

  const info = [];
  if (meta.language && meta.language !== "-") info.push(meta.language);
  if (meta.runtime && meta.runtime !== "-") info.push(meta.runtime);
  if (meta.score && meta.score !== "-") info.push("Score: " + meta.score);
  document.getElementById("mInfo").textContent = info.length ? info.join(" • ") : "-";

  document.getElementById("mCode").textContent = (typeof code === "string" && code.trim() !== "") ? code : "(no code)";
  document.getElementById("mMsg").textContent  = (typeof msg === "string" && msg.trim() !== "") ? msg : "(no message)";

  setTab("code");
  document.getElementById("subModalOverlay").style.display = "flex";
  document.body.style.overflow = "hidden";
}

function closeSubModal(){
  document.getElementById("subModalOverlay").style.display = "none";
  document.body.style.overflow = "";
}

function setTab(name){
  const code = document.getElementById("tabCode");
  const msg  = document.getElementById("tabMsg");
  const b1 = document.getElementById("tabCodeBtn");
  const b2 = document.getElementById("tabMsgBtn");

  if(name === "msg"){
    code.style.display = "none";
    msg.style.display  = "block";
    b1.classList.remove("active");
    b2.classList.add("active");
  } else {
    code.style.display = "block";
    msg.style.display  = "none";
    b2.classList.remove("active");
    b1.classList.add("active");
  }
}

document.addEventListener("keydown", function(e){
  if(e.key === "Escape") closeSubModal();
});
</script>

<?php ui_end(); ?>






