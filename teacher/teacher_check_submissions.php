<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$teacher_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($teacher_id <= 0) redirect("/uiu_brainnext/logout.php");

/* ---------------- DB helpers ---------------- */
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

function pick_first_col(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (db_has_col($conn, $table, $c)) return $c;
  }
  return null;
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
  if ($v === "CE" || $v === "RE" || $v === "TLE") return "pill pill-bad";
  if ($v === "MANUAL") return "pill pill-manual";
  if ($v === "") return "pill";
  return "pill";
}

/* ✅ language formatter: cpp -> C++ */
function fmt_lang($raw): string {
  $x = strtolower(trim((string)$raw));
  if ($x === "cpp") return "C++";
  if ($x === "c") return "C";
  if ($x === "") return "";
  return strtoupper($x);
}

/* ---------------- detect schema ---------------- */
$sub_user_col   = pick_first_col($conn, "submissions", ["user_id","student_id"]) ?? "user_id";
$sub_time_col   = pick_first_col($conn, "submissions", ["submitted_at","created_at","submitted_time"]);
$sub_status_col = pick_first_col($conn, "submissions", ["status","state"]) ?? "status";

$has_lang    = db_has_col($conn, "submissions", "language");
$has_verdict = db_has_col($conn, "submissions", "verdict");
$has_score   = db_has_col($conn, "submissions", "score");
$has_msg     = db_has_col($conn, "submissions", "message");
$has_rt      = db_has_col($conn, "submissions", "runtime_ms");
$has_code    = db_has_col($conn, "submissions", "answer_text");

$has_src_course  = db_has_col($conn, "submissions", "source_course_id");
$has_src_section = db_has_col($conn, "submissions", "source_section_id");

/* ---------------- teacher sections + courses ---------------- */
$section_ids = [];
$course_ids  = [];

$stS = $conn->prepare("SELECT id, course_id FROM sections WHERE teacher_id=?");
if ($stS) {
  $stS->bind_param("i", $teacher_id);
  $stS->execute();
  $rs = $stS->get_result();
  while ($rs && ($row = $rs->fetch_assoc())) {
    $sid = (int)($row["id"] ?? 0);
    if ($sid > 0) $section_ids[] = $sid;

    $cid = (int)($row["course_id"] ?? 0);
    if ($cid > 0 && !in_array($cid, $course_ids, true)) $course_ids[] = $cid;
  }
}
$restrict_sections = !empty($section_ids);
$restrict_courses  = !empty($course_ids);

/* ---------------- handle MANUAL check ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "manual_check") {
  $sid = (int)($_POST["submission_id"] ?? 0);
  $score = (int)($_POST["score"] ?? 0);

  if ($sid <= 0) {
    set_flash("err", "Invalid submission.");
    redirect("/uiu_brainnext/teacher/teacher_check_submissions.php");
  }

  $sets = [];
  $types = "";
  $vals = [];

  if (db_has_col($conn, "submissions", $sub_status_col)) {
    $sets[] = "$sub_status_col=?";
    $types .= "s";
    $vals[] = "Checked";
  }

  if ($has_verdict) {
    $sets[] = "verdict=?";
    $types .= "s";
    $vals[] = "MANUAL";
  }

  if ($has_score) {
    $sets[] = "score=?";
    $types .= "i";
    $vals[] = $score;
  }

  if ($has_msg) {
    $sets[] = "message=?";
    $types .= "s";
    $vals[] = "Manually checked";
  }

  if (empty($sets)) {
    set_flash("err", "Your submissions table has no updatable status/verdict/score columns.");
    redirect("/uiu_brainnext/teacher/teacher_check_submissions.php");
  }

  $sqlU = "UPDATE submissions SET " . implode(", ", $sets) . " WHERE id=? LIMIT 1";
  $stU = $conn->prepare($sqlU);
  if (!$stU) {
    set_flash("err", "Update failed: ".$conn->error);
    redirect("/uiu_brainnext/teacher/teacher_check_submissions.php");
  }

  $types .= "i";
  $vals[] = $sid;

  $bind = [];
  $bind[] = $types;
  for ($i=0; $i<count($vals); $i++) $bind[] = &$vals[$i];
  call_user_func_array([$stU, "bind_param"], $bind);

  if (!$stU->execute()) set_flash("err", "Update failed: ".$stU->error);
  else set_flash("ok", "Marked as Checked (Manual).");

  $qs = $_GET;
  redirect("/uiu_brainnext/teacher/teacher_check_submissions.php" . ($qs ? "?".http_build_query($qs) : ""));
}

/* ---------------- filters ---------------- */
$course_id = (int)($_GET["course_id"] ?? 0);
$status = trim((string)($_GET["status"] ?? ""));
$page = clamp_int($_GET["page"] ?? 1, 1, 999999);
$per  = clamp_int($_GET["per"] ?? 10, 5, 50);
$offset = ($page - 1) * $per;

/* ---------------- courses list for filter dropdown ---------------- */
$courses = [];
$r = $conn->query("SELECT id, code, title FROM courses ORDER BY code ASC");
while ($r && ($row = $r->fetch_assoc())) {
  if ($restrict_courses && !in_array((int)$row["id"], $course_ids, true)) continue;
  $courses[] = $row;
}

/* ---------------- OPTION-2 MODE check ---------------- */
$option2_mode = ($has_src_course && $has_src_section);

/* ---------------- query submissions ---------------- */
$where = " WHERE 1=1 ";
$params = [];
$types = "";

/*
  ✅ OPTION-2 restriction:
  - show ONLY tagged course-practice submissions
  - show ONLY from teacher's sections
*/
if ($option2_mode) {

  // If teacher has no sections, show nothing (no leakage)
  if (!$restrict_sections) {
    $where .= " AND 1=0 ";
  } else {
    $where .= " AND s.source_section_id IS NOT NULL ";

    $inS = implode(",", array_fill(0, count($section_ids), "?"));
    $where .= " AND s.source_section_id IN ($inS) ";
    foreach ($section_ids as $sid) { $params[] = $sid; $types .= "i"; }

    // course filter uses tagged course_id
    if ($course_id > 0) {
      $where .= " AND s.source_course_id = ? ";
      $params[] = $course_id;
      $types .= "i";
    }
  }

} else {
  // fallback (old behavior) if migration not done
  if ($restrict_courses) {
    $in = implode(",", array_fill(0, count($course_ids), "?"));
    $where .= " AND c.id IN ($in) ";
    foreach ($course_ids as $cid) { $params[] = $cid; $types .= "i"; }
  }
  if ($course_id > 0) {
    $where .= " AND c.id = ? ";
    $params[] = $course_id;
    $types .= "i";
  }
}

if ($status !== "") {
  $where .= " AND s.$sub_status_col = ? ";
  $params[] = $status;
  $types .= "s";
}

/* count */
if ($option2_mode) {
  $sqlCount = "
    SELECT COUNT(*) AS c
    FROM submissions s
    JOIN problems p ON p.id = s.problem_id
    JOIN users u ON u.id = s.$sub_user_col
    LEFT JOIN courses c ON c.id = s.source_course_id
    $where
  ";
} else {
  $sqlCount = "
    SELECT COUNT(*) AS c
    FROM submissions s
    JOIN problems p ON p.id = s.problem_id
    JOIN courses c ON c.id = p.course_id
    JOIN users u ON u.id = s.$sub_user_col
    $where
  ";
}

$stCount = $conn->prepare($sqlCount);
if ($types !== "") {
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => $v) $bind[] = &$params[$k];
  call_user_func_array([$stCount, "bind_param"], $bind);
}
$stCount->execute();
$total = (int)($stCount->get_result()->fetch_assoc()["c"] ?? 0);

/* fetch */
$select = "
  s.id,
  s.problem_id,
  s.$sub_status_col AS status,
  p.title AS problem_title,
  p.difficulty,
  u.username AS student_username,
  COALESCE(u.full_name, u.username) AS student_name
";

if ($option2_mode) {
  $select .= ",
    s.source_course_id,
    s.source_section_id,
    c.id AS course_id,
    c.code AS course_code,
    c.title AS course_title
  ";
} else {
  $select .= ",
    c.id AS course_id,
    c.code AS course_code,
    c.title AS course_title
  ";
}

if ($sub_time_col) $select .= ", s.$sub_time_col AS time";
else $select .= ", '' AS time";
if ($has_lang)    $select .= ", s.language";
if ($has_verdict) $select .= ", s.verdict";
if ($has_score)   $select .= ", s.score";
if ($has_msg)     $select .= ", s.message";
if ($has_rt)      $select .= ", s.runtime_ms";
if ($has_code)    $select .= ", s.answer_text";

if ($option2_mode) {
  $sql = "
    SELECT $select
    FROM submissions s
    JOIN problems p ON p.id = s.problem_id
    JOIN users u ON u.id = s.$sub_user_col
    LEFT JOIN courses c ON c.id = s.source_course_id
    $where
    ORDER BY s.id DESC
    LIMIT ? OFFSET ?
  ";
} else {
  $sql = "
    SELECT $select
    FROM submissions s
    JOIN problems p ON p.id = s.problem_id
    JOIN courses c ON c.id = p.course_id
    JOIN users u ON u.id = s.$sub_user_col
    $where
    ORDER BY s.id DESC
    LIMIT ? OFFSET ?
  ";
}

$params2 = $params;
$types2 = $types . "ii";
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
ui_start("Check Submissions", "Teacher Panel");
ui_top_actions([
  ["Dashboard", "/teacher/dashboard.php"],
  ["Check Submissions", "/teacher/teacher_check_submissions.php"],
]);

$err = get_flash("err");
$ok  = get_flash("ok");
if ($err) echo '<div class="alert err">'.e($err).'</div>';
if ($ok)  echo '<div class="alert ok">'.e($ok).'</div>';

/* if migration not done, warn */
if (!$option2_mode) {
  echo '<div class="card" style="border:1px solid rgba(255,120,0,.35);">
    <div style="font-weight:900;">Warning</div>
    <div class="muted">Your DB does not have <b>source_course_id</b> / <b>source_section_id</b> columns in <b>submissions</b>.
    Teacher view will fallback to old behavior. Run migration SQL first.</div>
  </div><div style="height:12px;"></div>';
}
?>
<style>
.pill{display:inline-block;padding:5px 9px;border-radius:999px;font-weight:900;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);}
.pill-ac{border-color:rgba(0,180,90,.35);background:rgba(0,180,90,.12);}
.pill-wa{border-color:rgba(255,180,0,.35);background:rgba(255,180,0,.10);}
.pill-bad{border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.10);}
.pill-manual{border-color:rgba(120,160,255,.35);background:rgba(120,160,255,.10);}
.smallmono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px;}
.muted{opacity:.78;}

/* ✅ Tighter spacing + tighter columns */
.rowhead,
.rowcard{
  display:grid;
  width:100%;
  box-sizing:border-box;
  gap:6px;
  align-items:center;
  grid-template-columns: 95px 1.15fr 1.10fr 1fr 95px 120px 70px 140px 95px;
}

.rowhead{
  font-weight:900;
  opacity:.9;
  padding:6px 2px;
}

.rowcard{
  border-radius:18px;
  border:1px solid rgba(255,255,255,.08);
  padding:10px 10px;
  margin-bottom:10px;
  background:rgba(10,15,25,.25);
  overflow:hidden;
}

.rowhead > div:nth-child(5), .rowcard > div:nth-child(5){text-align:center;}
.rowhead > div:nth-child(6), .rowcard > div:nth-child(6){text-align:center;}
.rowhead > div:nth-child(7), .rowcard > div:nth-child(7){text-align:center;}
.rowhead > div:nth-child(9), .rowcard > div:nth-child(9){text-align:right;}
.rowcard > div:nth-child(6) .pill{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

@media(max-width: 1100px){
  .rowhead, .rowcard{grid-template-columns: 95px 1fr 1fr;}
  .hide-lg{display:none;}
}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter: blur(4px);display:flex;align-items:center;justify-content:center;z-index:9999;padding:18px;}
.modal-hidden{display:none;}
.modal-box{width:min(980px, 96vw);max-height:82vh;overflow:auto;border-radius:18px;border:1px solid rgba(255,255,255,.12);background:rgba(10,15,25,.92);box-shadow:0 20px 80px rgba(0,0,0,.55);padding:16px 16px 18px;}
.modal-head{display:flex;justify-content:space-between;gap:10px;align-items:center;}
.modal-title{font-weight:900;}
.modal-close{cursor:pointer;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);border-radius:12px;padding:6px 10px;font-weight:900;}
.modal-pre{white-space:pre-wrap;margin:12px 0 0;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px;opacity:.95;}
</style>

<div class="card">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
    <div>
      <h3 style="margin-bottom:6px;">Submissions</h3>
      <div class="muted">
        <?= $option2_mode ? "Teacher sees <b>only Course Practice</b> submissions (tagged) from <b>their own sections</b>." : "Teacher sees submissions by course (old behavior)." ?>
      </div>
    </div>
    <div class="card" style="padding:10px 12px;">
      <div class="muted">Total</div>
      <div style="font-weight:900;font-size:18px;"><?= (int)$total ?></div>
    </div>
  </div>

  <div style="height:12px;"></div>

  <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
    <div style="min-width:240px;">
      <label class="label">Course</label>
      <select name="course_id">
        <option value="0">All</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c["id"] ?>" <?= $course_id===(int)$c["id"]?"selected":"" ?>>
            <?= e($c["code"]." - ".$c["title"]) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="min-width:200px;">
      <label class="label">Status</label>
      <select name="status">
        <option value="" <?= $status===""?"selected":"" ?>>All</option>
        <option value="Pending" <?= $status==="Pending"?"selected":"" ?>>Pending</option>
        <option value="Running" <?= $status==="Running"?"selected":"" ?>>Running</option>
        <option value="Checked" <?= $status==="Checked"?"selected":"" ?>>Checked</option>
      </select>
    </div>

    <div style="min-width:120px;">
      <label class="label">Per page</label>
      <select name="per">
        <?php foreach ([10,15,20,30,50] as $n): ?>
          <option value="<?= $n ?>" <?= $per===$n?"selected":"" ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <input type="hidden" name="page" value="1">
    <button class="btn-primary" type="submit">Apply</button>
  </form>
</div>

<div style="height:14px;"></div>

<?php if (empty($rows)): ?>
  <div class="card"><div class="muted">No submissions found.</div></div>
<?php else: ?>

  <div class="card">
    <div class="rowhead">
      <div class="hide-lg">Time</div>
      <div>Student</div>
      <div>Problem</div>
      <div class="hide-lg">Course</div>
      <div>Status</div>
      <div>Verdict</div>
      <div>Score</div>
      <div>Details</div>
      <div style="text-align:right;">Action</div>
    </div>

    <div style="height:8px;"></div>

    <?php foreach ($rows as $r): ?>
      <?php
        $ver = $has_verdict ? strtoupper((string)($r["verdict"] ?? "")) : "";
        $ver_disp = $ver;
        if ($ver_disp !== "" && strtoupper(substr($ver_disp,0,6)) === "MANUAL") $ver_disp = "MANUAL";

        $lang_disp = $has_lang ? fmt_lang($r["language"] ?? "") : "";
        $rt = $has_rt ? (string)($r["runtime_ms"] ?? "") : "";
        $msg = $has_msg ? (string)($r["message"] ?? "") : "";
        $code = $has_code ? (string)($r["answer_text"] ?? "") : "";
        $score = $has_score ? (string)($r["score"] ?? "") : "";
        $stt = (string)($r["status"] ?? "");
      ?>

      <div class="rowcard">
        <div class="smallmono hide-lg"><?= e((string)($r["time"] ?? "-")) ?></div>

        <div>
          <div style="font-weight:900;"><?= e((string)$r["student_name"]) ?></div>
          <div class="muted smallmono"><?= e((string)$r["student_username"]) ?></div>
        </div>

        <div>
          <div style="font-weight:900;"><?= e((string)$r["problem_title"]) ?></div>
          <div class="muted"><?= e((string)($r["difficulty"] ?? "")) ?></div>
        </div>

        <div class="hide-lg">
          <div style="font-weight:900;"><?= e((string)($r["course_code"] ?? "")) ?></div>
          <div class="muted"><?= e((string)($r["course_title"] ?? "")) ?></div>
        </div>

        <div><?= e($stt) ?></div>

        <div>
          <?php if ($ver !== ""): ?>
            <span class="<?= e(pill_class($ver_disp)) ?>"><?= e($ver_disp) ?></span>
          <?php else: ?>
            <span class="muted">-</span>
          <?php endif; ?>
        </div>

        <div style="font-weight:900;"><?= $score !== "" ? e($score) : "0" ?></div>

        <div>
          <button
            class="badge"
            type="button"
            onclick="openViewModal(this)"
            data-lang="<?= e($lang_disp) ?>"
            data-runtime="<?= e($rt) ?>"
            data-message="<?= e($msg) ?>"
            data-code="<?= e($code) ?>"
          >View</button>

          <?php if ($lang_disp !== ""): ?>
            <div class="muted smallmono" style="margin-top:4px;">Language: <?= e($lang_disp) ?></div>
          <?php endif; ?>
          <?php if ($rt !== ""): ?>
            <div class="muted smallmono">Runtime: <?= e($rt) ?> ms</div>
          <?php endif; ?>
        </div>

        <div style="text-align:right;">
          <?php if (strtolower($stt) === "checked"): ?>
            <span class="muted">Checked</span>
          <?php else: ?>
            <form method="POST" style="display:flex; gap:6px; justify-content:flex-end; align-items:center; flex-wrap:wrap;">
              <input type="hidden" name="action" value="manual_check">
              <input type="hidden" name="submission_id" value="<?= (int)$r["id"] ?>">
              <input type="number" name="score" min="0" max="100" value="0" style="width:70px;">
              <button class="badge" type="submit">Mark</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php
      $total_pages = (int)ceil(max(1, $total) / $per);
      $qs = $_GET;
    ?>
    <div style="height:10px;"></div>
    <div style="display:flex; gap:10px; justify-content:space-between; flex-wrap:wrap; align-items:center;">
      <div class="muted">Page: <?= (int)$page ?></div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php if ($page > 1): ?>
          <?php $qs["page"] = $page - 1; ?>
          <a class="badge" href="/uiu_brainnext/teacher/teacher_check_submissions.php?<?= http_build_query($qs) ?>">← Prev</a>
        <?php endif; ?>
        <span class="muted" style="padding:7px 10px;">Page <?= (int)$page ?> / <?= (int)$total_pages ?></span>
        <?php if ($page < $total_pages): ?>
          <?php $qs["page"] = $page + 1; ?>
          <a class="badge" href="/uiu_brainnext/teacher/teacher_check_submissions.php?<?= http_build_query($qs) ?>">Next →</a>
        <?php endif; ?>
      </div>
    </div>

  </div>
<?php endif; ?>

<!-- Modal (Popup) -->
<div id="modalOverlay" class="modal-overlay modal-hidden" onclick="closeModalFromOverlay()">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="modal-head">
      <div id="modalTitle" class="modal-title">View</div>
      <button class="modal-close" type="button" onclick="forceCloseModal()">X</button>
    </div>

    <div style="height:10px;"></div>
    <div id="modalMeta" class="muted smallmono"></div>

    <div style="height:12px;"></div>
    <div style="font-weight:900;">Judge Message</div>
    <pre id="modalMessage" class="modal-pre"></pre>

    <div style="height:12px;"></div>
    <div style="font-weight:900;">Code</div>
    <pre id="modalCode" class="modal-pre"></pre>
  </div>
</div>

<script>
function openViewModal(btn){
  const lang = btn.getAttribute("data-lang") || "";
  const rt   = btn.getAttribute("data-runtime") || "";
  const msg  = btn.getAttribute("data-message") || "";
  const code = btn.getAttribute("data-code") || "";

  document.getElementById("modalTitle").textContent = "Submission Details";

  let meta = "";
  if (lang) meta += "Language: " + lang + "   ";
  if (rt) meta += "Runtime: " + rt + " ms";
  document.getElementById("modalMeta").textContent = meta || "";

  document.getElementById("modalMessage").textContent = msg || "(no message)";
  document.getElementById("modalCode").textContent = code || "(no code found)";

  document.getElementById("modalOverlay").classList.remove("modal-hidden");
}

function forceCloseModal(){
  document.getElementById("modalOverlay").classList.add("modal-hidden");
}
function closeModalFromOverlay(){ forceCloseModal(); }

document.addEventListener("keydown", function(e){
  if (e.key === "Escape") forceCloseModal();
});
</script>

<?php ui_end(); ?>








