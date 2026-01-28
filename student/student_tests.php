<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* ✅ Fix timezone mismatch */
date_default_timezone_set("Asia/Dhaka");

/* ---------------- helpers ---------------- */
function db_has_col(mysqli $conn, string $table, string $col): bool {
  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = ?
      AND COLUMN_NAME  = ?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("ss", $table, $col);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}
function db_has_table(mysqli $conn, string $table): bool {
  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = ?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("s", $table);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}
function pick_col(mysqli $conn, string $table, array $cands): ?string {
  foreach ($cands as $c) if (db_has_col($conn, $table, $c)) return $c;
  return null;
}
function normalize_dt_db(?string $v): string {
  $v = trim((string)$v);
  if ($v === "" || $v === "0000-00-00 00:00:00") return "";
  return $v;
}
function fmt_dt_local(string $dbdt): string {
  if ($dbdt === "" || $dbdt === "0000-00-00 00:00:00") return "";
  $ts = strtotime($dbdt);
  if (!$ts) return $dbdt;
  return date("d M Y, h:i A", $ts);
}

/* ---------------- user ---------------- */
$user_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($user_id <= 0) redirect("/uiu_brainnext/logout.php");

/* ---------------- schema checks ---------------- */
if (!db_has_table($conn, "tests")) {
  set_flash("err", "Tests table not found.");
  redirect("/uiu_brainnext/student/dashboard.php");
}

$start_col = pick_col($conn, "tests", ["start_time","start_at","starts_at","start_datetime","start_date_time","open_time"]);
$end_col   = pick_col($conn, "tests", ["end_time","end_at","ends_at","end_datetime","end_date_time","close_time"]);
$has_time_window = ($start_col !== null && $end_col !== null);

/* detect submissions table */
$sub_table = null;
if (db_has_table($conn, "test_submissions")) $sub_table = "test_submissions";
else if (db_has_table($conn, "test_answers")) $sub_table = "test_answers";

$sub_user_col = $sub_table
  ? (db_has_col($conn, $sub_table, "user_id") ? "user_id" : (db_has_col($conn, $sub_table, "student_id") ? "student_id" : "user_id"))
  : null;

$sub_score_col = $sub_table
  ? (db_has_col($conn, $sub_table, "score") ? "score" : (db_has_col($conn, $sub_table, "marks") ? "marks" : null))
  : null;

$sub_ver_col = $sub_table ? (db_has_col($conn, $sub_table, "verdict") ? "verdict" : null) : null;

/* ---------------- fetch tests available for student ---------------- */
$tests = [];

$has_enroll = db_has_table($conn, "enrollments");
$has_sections = db_has_table($conn, "sections");
$has_courses = db_has_table($conn, "courses");

/* build query */
$select = "t.id AS test_id, t.section_id, t.*";
$join = "";
$where = "1=1";

if ($has_sections) {
  $join .= " LEFT JOIN sections s ON s.id = t.section_id ";
  $select .= ", s.section_label, s.trimester, s.year, s.course_id";
}
if ($has_courses) {
  $join .= " LEFT JOIN courses c ON c.id = s.course_id ";
  $select .= ", c.code AS course_code, c.title AS course_title";
}
if ($has_enroll) {
  $en_user_col = db_has_col($conn, "enrollments", "user_id") ? "user_id" : (db_has_col($conn, "enrollments", "student_id") ? "student_id" : "user_id");
  $join .= " JOIN enrollments e ON e.section_id = t.section_id AND e.`$en_user_col` = ? ";
}

$sql = "SELECT $select FROM tests t $join WHERE $where ORDER BY t.id DESC";
$st = $conn->prepare($sql);
if ($has_enroll) $st->bind_param("i", $user_id);
$st->execute();
$rs = $st->get_result();
while ($rs && ($row = $rs->fetch_assoc())) $tests[] = $row;

/* ---------------- attach my status/score (latest) ---------------- */
$myMap = []; // test_id => [verdict, score]
if ($sub_table && $sub_user_col) {
  $selScore = $sub_score_col ? ", $sub_score_col AS score" : ", NULL AS score";
  $selVer   = $sub_ver_col ? ", $sub_ver_col AS verdict" : ", '' AS verdict";

  $sqlS = "SELECT test_id $selScore $selVer
           FROM $sub_table
           WHERE $sub_user_col = ?
           ORDER BY id DESC";
  $stS = $conn->prepare($sqlS);
  $stS->bind_param("i", $user_id);
  $stS->execute();
  $rsS = $stS->get_result();

  while ($rsS && ($r = $rsS->fetch_assoc())) {
    $tid = (int)($r["test_id"] ?? 0);
    if ($tid <= 0) continue;
    if (!isset($myMap[$tid])) {
      $myMap[$tid] = [
        "score" => (string)($r["score"] ?? ""),
        "verdict" => (string)($r["verdict"] ?? "")
      ];
    }
  }
}

/* ---------------- UI ---------------- */
ui_start("Tests", "Student Panel");
ui_top_actions([
  ["Dashboard", "/student/dashboard.php"],
]);

$err = get_flash("err");
$ok  = get_flash("ok");
if ($err) echo '<div class="alert err">'.e($err).'</div>';
if ($ok)  echo '<div class="alert ok">'.e($ok).'</div>';

$TRI = [1=>"Spring",2=>"Summer",3=>"Fall"];
$now_ts = time();
?>

<style>
.test-card{padding:18px;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(10,15,25,.20);margin-bottom:14px;}
.test-title{font-weight:900;font-size:18px;margin:0;}
.test-meta{opacity:.85;margin-top:6px;}
.badgebtn{display:inline-flex;gap:10px;align-items:center;font-weight:900;padding:9px 12px;border-radius:999px;border:1px solid rgba(80,140,255,.30);background:rgba(80,140,255,.12);text-decoration:none;color:inherit;}
.badgebtn:hover{filter:brightness(1.06);}
.timerRow{margin-top:12px;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.05);display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between;}
.timepill{display:inline-flex;gap:10px;align-items:center;font-weight:900;padding:7px 10px;border-radius:999px;border:1px solid rgba(80,140,255,.30);background:rgba(80,140,255,.12);}
.timewarn{border-color:rgba(255,180,0,.35) !important;background:rgba(255,180,0,.10) !important;}
.timebad{border-color:rgba(255,80,80,.35) !important;background:rgba(255,80,80,.10) !important;}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}
.pill{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:900;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);}
.pill-ac{border-color:rgba(0,180,90,.35);background:rgba(0,180,90,.12);}
.pill-wa{border-color:rgba(255,180,0,.35);background:rgba(255,180,0,.10);}
.pill-bad{border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.10);}
.actionRow{margin-top:14px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
</style>

<div class="card">
  <h3 style="margin:0;">Available Tests</h3>
  <div class="muted" style="margin-top:6px;">Each test has its own leaderboard. Only checked scores will appear there.</div>

  <div style="height:12px;"></div>

  <?php if (empty($tests)): ?>
    <div class="muted">No tests available.</div>
  <?php else: ?>
    <?php foreach ($tests as $t): ?>
      <?php
        $test_id = (int)($t["test_id"] ?? $t["id"] ?? 0);

        $course_code  = (string)($t["course_code"] ?? "");
        $course_title = (string)($t["course_title"] ?? "");
        $section_label = (string)($t["section_label"] ?? "");
        $tri = (int)($t["trimester"] ?? 0);
        $year = (string)($t["year"] ?? "");

        // ✅ FIX: don't show T0
        $tn = $TRI[$tri] ?? "Fall";

        $metaLine = trim($course_code . " - " . $course_title, " -");
        $metaLine2 = trim(($section_label ? ("Section ".$section_label) : "") . ($tn ? (" • ".$tn) : "") . ($year ? (" / ".$year) : ""), " •/");

        $title = (string)($t["title"] ?? $t["test_title"] ?? $t["name"] ?? ("Test #".$test_id));

        // time window
        $start_dt = ($has_time_window && $start_col) ? normalize_dt_db($t[$start_col] ?? "") : "";
        $end_dt   = ($has_time_window && $end_col)   ? normalize_dt_db($t[$end_col] ?? "") : "";

        $start_ts = ($start_dt !== "") ? strtotime($start_dt) : null;
        $end_ts   = ($end_dt !== "") ? strtotime($end_dt) : null;

        $time_state = "none";
        if ($start_ts && $end_ts) {
          if ($now_ts < $start_ts) $time_state = "before";
          else if ($now_ts > $end_ts) $time_state = "ended";
          else $time_state = "open";
        }

        $remain = 0;
        if ($time_state === "open" && $end_ts) $remain = max(0, $end_ts - $now_ts);

        // submission info
        $verdict = strtoupper((string)($myMap[$test_id]["verdict"] ?? ""));
        $score   = (string)($myMap[$test_id]["score"] ?? "");
        $is_submitted = ($verdict !== "" || $score !== "");

        $statusText = $is_submitted ? "Submitted" : "Not Submitted";

        $vCls = "pill";
        if ($verdict === "AC") $vCls .= " pill-ac";
        else if ($verdict === "WA") $vCls .= " pill-wa";
        else if (in_array($verdict, ["CE","RE","TLE","ERR"], true)) $vCls .= " pill-bad";
      ?>

      <div class="test-card">
        <div class="test-meta"><?= e($metaLine) ?><?= $metaLine2 ? " • " . e($metaLine2) : "" ?></div>
        <p class="test-title"><?= e($title) ?></p>

        <?php if ($start_ts && $end_ts): ?>
          <div class="timerRow">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
              <span class="timepill">
                Start: <span class="mono"><?= e(fmt_dt_local($start_dt)) ?></span>
              </span>
              <span class="timepill <?= $time_state==="ended" ? "timebad" : ($time_state==="before" ? "timewarn" : "") ?>">
                End: <span class="mono"><?= e(fmt_dt_local($end_dt)) ?></span>
              </span>
            </div>
            <div>
              <?php if ($time_state === "open"): ?>
                <span class="timepill" data-countdown="1" data-remain="<?= (int)$remain ?>">
                  Remaining: <span class="mono">--:--:--</span>
                </span>
              <?php elseif ($time_state === "before"): ?>
                <span class="timepill timewarn">Not started</span>
              <?php elseif ($time_state === "ended"): ?>
                <span class="timepill timebad">Ended</span>
              <?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="muted" style="margin-top:10px;">No time window set.</div>
        <?php endif; ?>

        <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <span class="pill"><?= e($statusText) ?></span>
          <?php if ($verdict !== ""): ?><span class="<?= e($vCls) ?>"><?= e($verdict) ?></span><?php endif; ?>
          <?php if ($score !== ""): ?><span class="pill">Score: <?= e($score) ?></span><?php endif; ?>
        </div>

        <div class="actionRow">
          <?php if ($is_submitted): ?>
            <!-- ✅ submitted: show popup only, don't navigate -->
            <a class="badgebtn"
               href="#"
               data-submitted="1"
               onclick="return alreadySubmittedPopup(event);">View</a>
          <?php else: ?>
            <!-- ✅ not submitted: go to test view -->
            <a class="badgebtn" href="/uiu_brainnext/student/student_test_view.php?id=<?= (int)$test_id ?>">Open</a>
          <?php endif; ?>

          <a class="badgebtn" href="/uiu_brainnext/student/test_leaderboard.php?id=<?= (int)$test_id ?>">Leaderboard</a>
        </div>
      </div>

    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
function alreadySubmittedPopup(e){
  if (e) e.preventDefault();
  alert("You already submitted this test");
  return false;
}

(function(){
  function pad(n){ return String(n).padStart(2,"0"); }
  function tickAll(){
    const pills = document.querySelectorAll('[data-countdown="1"]');
    pills.forEach(pill => {
      let remain = parseInt(pill.getAttribute("data-remain") || "0", 10);
      const span = pill.querySelector(".mono");
      if (!span) return;

      if (remain <= 0){
        span.textContent = "00:00:00";
        pill.classList.add("timebad");
        return;
      }

      const h = Math.floor(remain / 3600);
      const m = Math.floor((remain % 3600) / 60);
      const s = remain % 60;

      span.textContent = pad(h)+":"+pad(m)+":"+pad(s);

      if (remain <= 120) pill.classList.add("timewarn");

      pill.setAttribute("data-remain", String(remain - 1));
    });

    setTimeout(tickAll, 1000);
  }
  tickAll();
})();
</script>

<?php ui_end(); ?>






