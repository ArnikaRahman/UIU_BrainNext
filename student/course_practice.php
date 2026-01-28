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
  if ($v === "CE" || $v === "RE" || $v === "TLE") return "pill pill-bad";
  if ($v === "MANUAL") return "pill pill-manual";
  if ($v === "") return "pill";
  return "pill";
}

/* ---------------- user ---------------- */
$uid = (int)($_SESSION["user"]["id"] ?? 0);
if ($uid <= 0) redirect("/uiu_brainnext/logout.php");

/*
  ✅ Always fetch enrollments fresh from DB (session may be missing on other pages)
*/
$enrollments = [];
$stE = $conn->prepare("
  SELECT
    e.section_id,
    s.course_id,
    s.section_label,
    s.trimester,
    s.year,
    c.code AS course_code,
    c.title AS course_title
  FROM enrollments e
  JOIN sections s ON s.id = e.section_id
  JOIN courses c ON c.id = s.course_id
  WHERE e.student_id = ?
  ORDER BY c.code ASC, s.section_label ASC
");
$stE->bind_param("i", $uid);
$stE->execute();
$rE = $stE->get_result();
while ($rE && ($row = $rE->fetch_assoc())) $enrollments[] = $row;

ui_start("Course Practice", "Student Panel");

ui_top_actions([
  ["Dashboard", "/student/dashboard.php"],
  ["Global Practice", "/student/problems.php"],
  ["Tests", "/student/student_tests.php"],
]);

if (!$enrollments) {
  echo '<div class="card"><h3>No enrollments</h3><div class="muted">You are not enrolled in any section yet.</div></div>';
  ui_end();
  exit;
}

/* ---------------- choose section ---------------- */
$section_id = (int)($_GET["section_id"] ?? 0);
if ($section_id <= 0) $section_id = (int)($enrollments[0]["section_id"] ?? 0);

$selected = null;
foreach ($enrollments as $row) {
  if ((int)$row["section_id"] === $section_id) { $selected = $row; break; }
}
if (!$selected) $selected = $enrollments[0];

$course_id  = (int)($selected["course_id"] ?? 0);
$section_id = (int)($selected["section_id"] ?? 0);

/* ---------------- schema detect ---------------- */
$sub_user_col = db_has_col($conn, "submissions", "user_id")
  ? "user_id"
  : (db_has_col($conn, "submissions", "student_id") ? "student_id" : "user_id");

$sub_time_col = db_has_col($conn, "submissions", "submitted_at")
  ? "submitted_at"
  : (db_has_col($conn, "submissions", "created_at") ? "created_at" : "submitted_at");

$has_verdict  = db_has_col($conn, "submissions", "verdict");
$has_score    = db_has_col($conn, "submissions", "score");
$has_lang     = db_has_col($conn, "submissions", "language");
$has_short    = db_has_col($conn, "problems", "short_title");

/* ---------------- filters (same as global) ---------------- */
$q = trim((string)($_GET["q"] ?? ""));
$diff = trim((string)($_GET["diff"] ?? ""));
$show = trim((string)($_GET["show"] ?? "all")); // all/solved/unsolved

$page = clamp_int($_GET["page"] ?? 1, 1, 999999);
$per  = clamp_int($_GET["per"] ?? 15, 5, 50);
$offset = ($page - 1) * $per;

/* ---------------- build solved map (same as global) ---------------- */
$stats = []; // problem_id => solved/last_verdict/best_score/last_time/last_lang

if ($has_verdict) {
  $select = "
    problem_id,
    MAX(CASE WHEN UPPER(verdict)='AC' THEN 1 ELSE 0 END) AS solved,
    SUBSTRING_INDEX(GROUP_CONCAT(verdict ORDER BY id DESC SEPARATOR ','), ',', 1) AS last_verdict,
    MAX($sub_time_col) AS last_time
  ";
  if ($has_score) $select .= ", MAX(score) AS best_score";
  if ($has_lang)  $select .= ", SUBSTRING_INDEX(GROUP_CONCAT(language ORDER BY id DESC SEPARATOR ','), ',', 1) AS last_lang";

  $st = $conn->prepare("
    SELECT $select
    FROM submissions
    WHERE $sub_user_col = ?
    GROUP BY problem_id
  ");
  $st->bind_param("i", $uid);
  $st->execute();
  $r = $st->get_result();
  while ($r && ($row = $r->fetch_assoc())) {
    $pid = (int)$row["problem_id"];
    $stats[$pid] = [
      "solved" => (int)($row["solved"] ?? 0),
      "last_verdict" => (string)($row["last_verdict"] ?? ""),
      "best_score" => (int)($row["best_score"] ?? 0),
      "last_time" => (string)($row["last_time"] ?? ""),
      "last_lang" => (string)($row["last_lang"] ?? ""),
    ];
  }
}

/* ---------------- list ONLY selected course problems ---------------- */
$where = " WHERE p.course_id = ? ";
$params = [$course_id];
$types  = "i";

if ($q !== "") {
  $like = "%".$q."%";
  if ($has_short) {
    $where .= " AND (p.title LIKE ? OR p.short_title LIKE ?) ";
    $params[] = $like; $params[] = $like;
    $types .= "ss";
  } else {
    $where .= " AND (p.title LIKE ?) ";
    $params[] = $like;
    $types .= "s";
  }
}

if ($diff !== "" && in_array($diff, ["Easy","Medium","Hard"], true)) {
  $where .= " AND p.difficulty = ? ";
  $params[] = $diff;
  $types .= "s";
}

/* count */
$stc = $conn->prepare("
  SELECT COUNT(*) c
  FROM problems p
  $where
");
$bind = [];
$bind[] = $types;
foreach ($params as $k => $v) $bind[] = &$params[$k];
call_user_func_array([$stc, "bind_param"], $bind);

$stc->execute();
$total = (int)($stc->get_result()->fetch_assoc()["c"] ?? 0);

/* fetch */
$selectP = "p.id, p.course_id, p.title, p.difficulty, p.points";
if ($has_short) $selectP .= ", p.short_title";

$sql = "
  SELECT $selectP
  FROM problems p
  $where
  ORDER BY p.id ASC
  LIMIT ? OFFSET ?
";

$params2 = $params;
$types2 = $types . "ii";
$params2[] = $per;
$params2[] = $offset;

$stp = $conn->prepare($sql);
$bind2 = [];
$bind2[] = $types2;
foreach ($params2 as $k => $v) $bind2[] = &$params2[$k];
call_user_func_array([$stp, "bind_param"], $bind2);

$stp->execute();
$resP = $stp->get_result();

$rows = [];
while ($resP && ($row = $resP->fetch_assoc())) $rows[] = $row;

/* apply solved/unsolved filter AFTER fetch */
if ($show === "solved" || $show === "unsolved") {
  $rows = array_values(array_filter($rows, function($r) use ($stats, $show) {
    $pid = (int)$r["id"];
    $solved = (int)($stats[$pid]["solved"] ?? 0);
    return $show === "solved" ? ($solved === 1) : ($solved === 0);
  }));
}
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
.fcol-3{grid-column: span 3;}
.fcol-4{grid-column: span 4;}
.fcol-2{grid-column: span 2;}
.fcol-6{grid-column: span 6;}
.fcol-12{grid-column: span 12;}
.filters input,.filters select{width:100%; box-sizing:border-box;}

.pill{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:900;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);}
.pill-ac{border-color:rgba(0,180,90,.35);background:rgba(0,180,90,.12);}
.pill-wa{border-color:rgba(255,180,0,.35);background:rgba(255,180,0,.10);}
.pill-bad{border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.10);}
.pill-manual{border-color:rgba(120,160,255,.35);background:rgba(120,160,255,.10);}
.smallmono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px;}

.lang-pill{
  display:inline-block;
  padding:6px 10px;
  border-radius:999px;
  font-weight:900;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.06);
}

/* ✅ clickable full row (course practice) */
.row-link{
  display:block;
  text-decoration:none;
  color:inherit;
}
.row-link:hover .row-box{
  border-color: rgba(255,255,255,.18);
  transform: translateY(-1px);
}
.row-box{
  display:flex;
  justify-content:space-between;
  gap:14px;
  padding:16px 18px;
  border-radius:18px;
  border:1px solid rgba(255,255,255,.08);
  background:rgba(10,15,25,.35);
  margin-bottom:12px;
  transition: transform .08s ease, border-color .12s ease, background .12s ease;
}

/* ✅ solved = green whole card */
.row-box.solved{
  border-color: rgba(0,180,90,.28);
  background: rgba(0,180,90,.10);
}
.row-box.solved:hover{
  border-color: rgba(0,180,90,.38);
  background: rgba(0,180,90,.14);
}

.row-left{min-width:0;}
.row-title{font-weight:900;font-size:18px;margin:0 0 6px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.row-sub{display:flex;flex-wrap:wrap;gap:8px;align-items:center;opacity:.9;font-size:12px;}
.tag{
  display:inline-block;
  padding:5px 10px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(255,255,255,.06);
  font-weight:800;
}
.row-right{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end;}
@media(max-width:900px){
  .row-box{flex-direction:column; align-items:flex-start;}
  .row-right{justify-content:flex-start;}
}
</style>

<div class="card">
  <h3 style="margin-bottom:8px;">Your Course Practice</h3>

  <!-- section selector -->
  <form method="GET" class="filters" style="margin-bottom:12px;">
    <div class="fcol-6">
      <label class="label">Select Section</label>
      <select name="section_id">
        <?php foreach ($enrollments as $row): ?>
          <?php
            $sid = (int)$row["section_id"];
            $label = ($row["course_code"] ?? "") . " — " . ($row["section_label"] ?? "") . " (" . ($row["trimester"] ?? "") . " " . ($row["year"] ?? "") . ")";
          ?>
          <option value="<?= $sid ?>" <?= $sid===$section_id?"selected":"" ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fcol-2">
      <button class="btn-primary" type="submit" style="margin-top:22px;">Open</button>
    </div>

    <div class="fcol-12 muted">
      Course: <b><?= e($selected["course_code"] ?? "") ?></b> — <?= e($selected["course_title"] ?? "") ?>
    </div>
  </form>

  <div style="height:8px;"></div>

  <!-- same filters as global -->
  <form method="GET" class="filters">
    <input type="hidden" name="section_id" value="<?= (int)$section_id ?>">

    <div class="fcol-4">
      <label class="label">Search</label>
      <input name="q" value="<?= e($q) ?>" placeholder="problem title">
    </div>

    <div class="fcol-3">
      <label class="label">Difficulty</label>
      <select name="diff">
        <option value="">All</option>
        <option value="Easy" <?= $diff==="Easy"?"selected":"" ?>>Easy</option>
        <option value="Medium" <?= $diff==="Medium"?"selected":"" ?>>Medium</option>
        <option value="Hard" <?= $diff==="Hard"?"selected":"" ?>>Hard</option>
      </select>
    </div>

    <div class="fcol-3">
      <label class="label">Show</label>
      <select name="show">
        <option value="all" <?= $show==="all"?"selected":"" ?>>All</option>
        <option value="solved" <?= $show==="solved"?"selected":"" ?>>Solved</option>
        <option value="unsolved" <?= $show==="unsolved"?"selected":"" ?>>Unsolved</option>
      </select>
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
      <button class="btn-primary" type="submit">Apply</button>
      <a class="badge" href="/uiu_brainnext/student/course_practice.php?section_id=<?= (int)$section_id ?>">Reset</a>
    </div>
  </form>
</div>

<div style="height:14px;"></div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="muted">No problems found for this course.</div>
  <?php else: ?>

    <?php foreach ($rows as $r): ?>
      <?php
        $pid = (int)$r["id"];
        $solved = (int)($stats[$pid]["solved"] ?? 0);
        $last_v = strtoupper((string)($stats[$pid]["last_verdict"] ?? ""));
        $last_lang_raw = strtolower(trim((string)($stats[$pid]["last_lang"] ?? "")));
        $last_lang = ($last_lang_raw === "cpp") ? "C++" : (($last_lang_raw === "c") ? "C" : strtoupper($last_lang_raw));

        $openUrl = "/uiu_brainnext/student/problem_view.php?id={$pid}&ctx=course&course_id={$course_id}&section_id={$section_id}";
        $boxClass = "row-box" . ($solved ? " solved" : "");
      ?>

      <a class="row-link" href="<?= e($openUrl) ?>">
        <div class="<?= e($boxClass) ?>">
          <div class="row-left">
            <div class="row-title"><?= e((string)$r["title"]) ?></div>

            <div class="row-sub">
              <span class="tag"><?= e((string)($r["difficulty"] ?? "")) ?></span>

              <?php if (!empty($r["short_title"])): ?>
                <span class="tag"><?= e((string)$r["short_title"]) ?></span>
              <?php endif; ?>

              <?php if (!empty($stats[$pid]["last_time"])): ?>
                <span class="tag" style="opacity:.85;">Last: <?= e((string)$stats[$pid]["last_time"]) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="row-right">
            <?php if ($solved): ?>
              <span class="pill pill-ac">Solved</span>
            <?php else: ?>
              <span class="pill">Unsolved</span>
            <?php endif; ?>

            <?php if ($has_verdict && $last_v !== ""): ?>
              <span class="<?= e(pill_class($last_v)) ?>"><?= e($last_v) ?></span>
            <?php else: ?>
              <span class="pill">-</span>
            <?php endif; ?>

            <?php if ($has_lang && $last_lang !== ""): ?>
              <span class="lang-pill"><?= e($last_lang) ?></span>
            <?php else: ?>
              <span class="lang-pill">-</span>
            <?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>

    <?php
      $total_pages = (int)ceil(max(1, $total) / $per);
      $qs = $_GET;
    ?>
    <div style="height:12px;"></div>
    <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
      <?php if ($page > 1): ?>
        <?php $qs["page"] = $page - 1; ?>
        <a class="badge" href="/uiu_brainnext/student/course_practice.php?<?= http_build_query($qs) ?>">← Prev</a>
      <?php endif; ?>
      <span class="muted" style="padding:7px 10px;">Page <?= (int)$page ?> / <?= (int)$total_pages ?></span>
      <?php if ($page < $total_pages): ?>
        <?php $qs["page"] = $page + 1; ?>
        <a class="badge" href="/uiu_brainnext/student/course_practice.php?<?= http_build_query($qs) ?>">Next →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php ui_end(); ?>







