<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

// Auto judge (local, Windows) - C/C++ only
$judge_file = __DIR__ . "/../includes/judge_local.php";
$judge_enabled = file_exists($judge_file);
if ($judge_enabled) {
  require_once $judge_file;
}

if (session_status() === PHP_SESSION_NONE) session_start();

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

/* ---------------- CLEAN JUDGE MESSAGE (SHORT) ---------------- */
function sanitize_judge_msg(string $text): string {
  $t = (string)$text;
  $t = str_replace(["\r\n", "\r"], "\n", $t);

  // Remove Windows abs paths up to main.c/main.cpp
  $t = preg_replace('~[A-Za-z]:\\\\[^\n]*?(main\.(?:c|cpp))~i', '$1', $t);
  // Remove Unix abs paths up to main.c/main.cpp
  $t = preg_replace('~/(?:[^/\n]+/)*?(main\.(?:c|cpp))~i', '$1', $t);
  // Remove sandbox run folder path like "...sandbox/run_xxx/main.cpp"
  $t = preg_replace('~(?:^|[\s"\'])[^ \n"\']*sandbox[\\\\/][^ \n"\']*[\\\\/](main\.(?:c|cpp))~i', ' $1', $t);

  // Remove "In function '...':" lines
  $t = preg_replace('/^.*In function.*\n/m', '', $t);
  // Remove caret-only lines "^"
  $t = preg_replace('/^\s*\^\s*$/m', '', $t);

  $lines = explode("\n", $t);
  $keep = [];
  foreach ($lines as $ln) {
    $trim = trim($ln);
    if ($trim === "") continue;

    if (preg_match('/(fatal error:|error:|warning:|note:|undefined reference)/i', $trim)) {
      $keep[] = $ln;
      continue;
    }
    if (preg_match('/^main\.(c|cpp):\d+:\d+:/i', $trim)) {
      $keep[] = $ln;
      continue;
    }
    if (preg_match('/compilation terminated/i', $trim)) {
      $keep[] = $ln;
      continue;
    }
  }

  $out = trim(implode("\n", $keep));
  if ($out === "") $out = trim($t);

  $out = preg_replace("/\n{3,}/", "\n\n", $out);
  return trim($out);
}

/**
 * Insert submission dynamically (works even if some columns missing)
 * Returns [ok(bool), error(string)]
 */
function insert_submission(mysqli $conn, array $data): array {
  $possible = [
    "user_id" => "i",
    "student_id" => "i",
    "problem_id" => "i",
    "language" => "s",
    "answer_text" => "s",
    "score" => "i",
    "status" => "s",
    "verdict" => "s",
    "message" => "s",
    "runtime_ms" => "i",

    // ✅ Option-2 tagging columns
    "source_course_id" => "i",
    "source_section_id" => "i",
  ];

  $cols = [];
  $types = "";
  $vals = [];

  foreach ($possible as $col => $t) {
    if (!array_key_exists($col, $data)) continue;
    if (!db_has_col($conn, "submissions", $col)) continue;

    $cols[] = $col;
    $types .= $t;
    $vals[] = $data[$col];
  }

  if (empty($cols)) return [false, "No matching columns found in submissions table."];

  $place = implode(",", array_fill(0, count($cols), "?"));
  $sql = "INSERT INTO submissions (" . implode(",", $cols) . ") VALUES ($place)";
  $st = $conn->prepare($sql);
  if (!$st) return [false, "Prepare failed: " . $conn->error];

  $bind = [];
  $bind[] = $types;
  for ($i = 0; $i < count($vals); $i++) $bind[] = &$vals[$i];
  call_user_func_array([$st, "bind_param"], $bind);

  if (!$st->execute()) return [false, "Execute failed: " . $st->error];
  return [true, ""];
}

/* ---------------- user + schema ---------------- */
$user_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($user_id <= 0) redirect("/uiu_brainnext/logout.php");

$sub_user_col = db_has_col($conn, "submissions", "user_id") ? "user_id" : "student_id";

$sub_time_col = db_has_col($conn, "submissions", "submitted_at")
  ? "submitted_at"
  : (db_has_col($conn, "submissions", "created_at") ? "created_at" : "submitted_at");

$has_verdict  = db_has_col($conn, "submissions", "verdict");
$has_message  = db_has_col($conn, "submissions", "message");
$has_runtime  = db_has_col($conn, "submissions", "runtime_ms");
$has_language = db_has_col($conn, "submissions", "language");
$has_score    = db_has_col($conn, "submissions", "score");
$has_status   = db_has_col($conn, "submissions", "status");
$has_answer_text = db_has_col($conn, "submissions", "answer_text");

$has_src_course  = db_has_col($conn, "submissions", "source_course_id");
$has_src_section = db_has_col($conn, "submissions", "source_section_id");

/* ---------------- context (Option-2 tagging) ----------------
   If opened from Course Practice page:
   problem_view.php?id=..&ctx=course&course_id=..&section_id=..
*/
$ctx = strtolower(trim((string)($_GET["ctx"] ?? "")));
$ctx_course_id = (int)($_GET["course_id"] ?? 0);
$ctx_section_id = (int)($_GET["section_id"] ?? 0);

$ctx_ok = false;
if ($ctx === "course" && $ctx_course_id > 0 && $ctx_section_id > 0) {
  $en = $_SESSION["student_enrollments"] ?? [];
  foreach ($en as $er) {
    if ((int)($er["section_id"] ?? 0) === $ctx_section_id && (int)($er["course_id"] ?? 0) === $ctx_course_id) {
      $ctx_ok = true;
      break;
    }
  }
}
$ctx_qs = ($ctx_ok ? "&ctx=course&course_id={$ctx_course_id}&section_id={$ctx_section_id}" : "");

/* ---------------- get problem ---------------- */
$pid = (int)($_GET["id"] ?? 0);
if ($pid <= 0) redirect("/uiu_brainnext/student/problems.php");

$has_short = db_has_col($conn, "problems", "short_title");
$has_in    = db_has_col($conn, "problems", "sample_input");
$has_out   = db_has_col($conn, "problems", "sample_output");

$select = "p.id, p.course_id, p.title, p.statement, p.difficulty, p.points,
           c.code AS course_code, c.title AS course_title";
if ($has_short) $select .= ", p.short_title";
if ($has_in)    $select .= ", p.sample_input";
if ($has_out)   $select .= ", p.sample_output";

$st = $conn->prepare("
  SELECT $select
  FROM problems p
  JOIN courses c ON c.id = p.course_id
  WHERE p.id = ?
  LIMIT 1
");
$st->bind_param("i", $pid);
$st->execute();
$p = $st->get_result()->fetch_assoc();

if (!$p) {
  ui_start("Problem", "Student Panel");
  echo '<div class="card"><h3>Problem not found</h3><div class="muted">Invalid ID.</div></div>';
  ui_end();
  exit;
}

/* ---------------- fetch LAST submission ---------------- */
$last = null;
if ($has_answer_text) {
  $selLast = "s.id, s.answer_text AS code, s.$sub_time_col AS t";
  if ($has_language) $selLast .= ", s.language";
  if ($has_status)   $selLast .= ", s.status";
  if ($has_verdict)  $selLast .= ", s.verdict";
  if ($has_message)  $selLast .= ", s.message";
  if ($has_runtime)  $selLast .= ", s.runtime_ms";
  if ($has_score)    $selLast .= ", s.score";

  $stL = $conn->prepare("
    SELECT $selLast
    FROM submissions s
    WHERE s.$sub_user_col = ?
      AND s.problem_id = ?
    ORDER BY s.id DESC
    LIMIT 1
  ");
  $stL->bind_param("ii", $user_id, $pid);
  $stL->execute();
  $last = $stL->get_result()->fetch_assoc();
}

/* ✅ do NOT auto-fill code textarea */
$prefill_code = "";

/* ---------------- handle submission ---------------- */
$ok = get_flash("ok");
$err = get_flash("err");

$judge_json = get_flash("judge");
$judge_view = null;
if ($judge_json) {
  $tmp = json_decode($judge_json, true);
  if (json_last_error() === JSON_ERROR_NONE) $judge_view = $tmp;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  // ✅ absolute safety: never let a submit hang forever
  set_time_limit(12);
  ini_set("max_execution_time", "12");

  $code = trim((string)($_POST["answer"] ?? ""));
  $lang = strtolower(trim((string)($_POST["language"] ?? "c")));

  if ($code === "") {
    set_flash("err", "Please write your code before submitting.");
    redirect("/uiu_brainnext/student/problem_view.php?id=" . $pid . $ctx_qs);
  }
  if (!in_array($lang, ["c", "cpp"], true)) {
    set_flash("err", "Only C / C++ are allowed.");
    redirect("/uiu_brainnext/student/problem_view.php?id=" . $pid . $ctx_qs);
  }

  $points = (int)($p["points"] ?? 0);

  $status = "Pending";
  $verdict = null;
  $message = null;
  $runtime_ms = null;
  $score = null;

  // ✅ best-effort judge (must never block submission)
  if ($judge_enabled && function_exists("load_problem_cases") && function_exists("local_judge")) {
    try {
      // ⏱️ try to keep judge inside a small window
      set_time_limit(8);
      ini_set("max_execution_time", "8");

      $cases = load_problem_cases($pid);

      if (!empty($cases)) {
        $res = local_judge($code, $lang, $cases);

        $verdict = (string)($res["verdict"] ?? "RE");
        $status  = "Checked";

        if (!empty($res["case_results"]) && is_array($res["case_results"])) {
          $sum = 0;
          foreach ($res["case_results"] as $cr) $sum += (int)($cr["time_ms"] ?? 0);
          $runtime_ms = $sum;
        }

        if ($verdict === "CE") {
          $message = sanitize_judge_msg((string)($res["compile_log"] ?? "Compile Error"));
        } elseif ($verdict === "WA" && !empty($res["first_fail"])) {
          $ff = $res["first_fail"];
          $message = "WA on case " . (int)($ff["case_no"] ?? 0);
          if (isset($ff["expected"], $ff["got"])) {
            $message .= "\nExpected:\n" . trim((string)$ff["expected"]) . "\n\nGot:\n" . trim((string)$ff["got"]);
          }
        } elseif ($verdict === "TLE") {
          $message = "Time limit exceeded";
        } elseif ($verdict === "RE") {
          $message = "Runtime error";
          if (!empty($res["first_fail"]["stderr"])) {
            $message .= "\n" . sanitize_judge_msg((string)$res["first_fail"]["stderr"]);
          }
        } else {
          $message = "Accepted";
        }

        $score = ($verdict === "AC") ? ($points > 0 ? $points : 10) : 0;

        set_flash("judge", json_encode([
          "verdict" => $verdict,
          "runtime_ms" => $runtime_ms,
          "message" => (string)$message,
        ]));
      }
    } catch (Throwable $e) {
      // ✅ FAIL SAFE — DO NOT BLOCK SUBMISSION
      $status = "Checked";
      $verdict = "CE";
      $message = "Judge timeout / internal error";
      $runtime_ms = null;
      $score = 0;

      set_flash("judge", json_encode([
        "verdict" => "CE",
        "runtime_ms" => null,
        "message" => $message,
      ]));
    }
  }

  $data = [
    $sub_user_col => $user_id,
    "problem_id" => $pid,
    "language" => $lang,
    "answer_text" => $code,
    "status" => $status,
  ];

  if ($verdict !== null) $data["verdict"] = $verdict;
  if ($message !== null) $data["message"] = $message;
  if ($runtime_ms !== null) $data["runtime_ms"] = (int)$runtime_ms;
  if ($score !== null) $data["score"] = (int)$score;

  // ✅ Option-2 tagging (server-side verified)
  $post_src_course  = (int)($_POST["source_course_id"] ?? 0);
  $post_src_section = (int)($_POST["source_section_id"] ?? 0);

  if ($has_src_course && $has_src_section && $post_src_course > 0 && $post_src_section > 0) {
    $okEnroll = false;
    $en = $_SESSION["student_enrollments"] ?? [];
    foreach ($en as $er) {
      if ((int)($er["section_id"] ?? 0) === $post_src_section && (int)($er["course_id"] ?? 0) === $post_src_course) {
        $okEnroll = true;
        break;
      }
    }
    if ($okEnroll) {
      $data["source_course_id"]  = $post_src_course;
      $data["source_section_id"] = $post_src_section;
    }
  }

  [$ins_ok, $ins_err] = insert_submission($conn, $data);

  if ($ins_ok) {
    if ($verdict) set_flash("ok", "Submitted! Verdict: " . $verdict);
    else set_flash("ok", "Submitted successfully! (Pending manual check)");
  } else {
    set_flash("err", "Submission failed: " . $ins_err);
  }

  redirect("/uiu_brainnext/student/problem_view.php?id=" . $pid . $ctx_qs);
}

/* ---------------- UI ---------------- */
ui_start("Problem", "Student Panel");

/* back link depends on context */
$back_url = "/uiu_brainnext/student/problems.php";
$back_label = "← Problems";

if ($ctx_ok) {
  $back_url = "/uiu_brainnext/student/course_practice.php?section_id=" . (int)$ctx_section_id;
  $back_label = "← Course Practice";
}
?>
<style>
.qbox{padding:22px 24px;border-radius:18px;border:1px solid rgba(255,255,255,.08);background:rgba(20,30,45,.55);box-shadow:0 12px 40px rgba(0,0,0,.35);}
.qtext{margin:0;font-weight:900;font-size:22px;line-height:1.35;text-align:left;white-space:normal;word-break:break-word;}
.sample-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:900px){.sample-grid{grid-template-columns:1fr;}}
.sample-card{padding:16px 18px;border-radius:16px;border:1px solid rgba(255,255,255,.08);background:rgba(10,15,25,.35);}
.sample-title{font-weight:900;margin-bottom:10px;}
.sample-pre{margin:0;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px;opacity:.95;}
.smallmono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px;}
.pill{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:900;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);}
.pill-ac{border-color:rgba(0,180,90,.35);background:rgba(0,180,90,.12);}
.pill-wa{border-color:rgba(255,180,0,.35);background:rgba(255,180,0,.10);}
.pill-bad{border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.10);}

/* Modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:9999;}
.modal-box{width:min(920px,92vw);max-height:80vh;overflow:hidden;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(10,18,30,.96);box-shadow:0 20px 80px rgba(0,0,0,.6);}
.modal-head{display:flex;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08);align-items:center;}
.modal-title{font-weight:900;}
.modal-actions{display:flex;gap:8px;align-items:center;}
.modal-close{cursor:pointer;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#fff;padding:6px 10px;border-radius:999px;}
.modal-body{padding:14px 16px;overflow:auto;max-height:calc(80vh - 60px);}
.modal-pre{margin:0;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px;line-height:1.45;}

/* AI UI */
.ai-card{
  margin-top:10px;
  border-radius:16px;
  border:1px solid rgba(255,255,255,.08);
  background:rgba(10,15,25,.35);
  padding:14px 14px;
}
.ai-title{font-weight:900;margin-bottom:10px;}
.ai-pre{margin:0;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px;line-height:1.45;opacity:.95;}
.ai-error{color:#ffb4b4;font-weight:900;}
.ai-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;}
.ai-meta{margin-top:10px;opacity:.85;font-size:12px;}

/* ✅ Spinner + disabled button (new) */
.spinner{
  display:inline-block;
  width:14px;
  height:14px;
  margin-right:8px;
  border:2px solid currentColor;
  border-top-color: transparent;
  border-radius:50%;
  vertical-align:-2px;
  animation: spin .8s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg);}}
.badge.loading{opacity:.85;cursor:not-allowed;}
</style>

<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <a class="badge" href="<?= e($back_url) ?>"><?= e($back_label) ?></a>
    <div class="muted" style="font-weight:900;">
      <?= e($p["course_code"] ?? "") ?> • <?= e($p["difficulty"] ?? "") ?>
      <?php if ($ctx_ok): ?>
        • <span class="pill" style="padding:4px 10px;">Course Practice</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<div style="height:14px;"></div>

<?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>

<?php if ($judge_view): ?>
  <div class="card" style="margin-bottom:14px;">
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <div style="font-weight:900;">Auto Judge Result</div>
      <?php
        $v = strtoupper((string)($judge_view["verdict"] ?? ""));
        $cls = "pill";
        if ($v === "AC") $cls .= " pill-ac";
        elseif ($v === "WA") $cls .= " pill-wa";
        elseif (in_array($v, ["CE","RE","TLE"], true)) $cls .= " pill-bad";
      ?>
      <span class="<?= $cls ?>"><?= e($v) ?></span>
      <?php if (!empty($judge_view["runtime_ms"])): ?>
        <span class="muted smallmono">Runtime: <?= (int)$judge_view["runtime_ms"] ?> ms</span>
      <?php endif; ?>

      <?php if (!empty($judge_view["message"])): ?>
        <button type="button" class="badge"
          onclick="openModal('Auto Judge Message', document.getElementById('auto_judge_msg_raw').textContent)">
          View Message
        </button>
        <pre id="auto_judge_msg_raw" style="display:none;"><?= e((string)$judge_view["message"]) ?></pre>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="qbox">
  <h2 class="qtext"><?= e((string)$p["title"]) ?></h2>
  <?php if (!empty($p["statement"])): ?>
    <div class="muted" style="margin-top:12px; line-height:1.6;">
      <?= nl2br(e((string)$p["statement"])) ?>
    </div>
  <?php endif; ?>
</div>

<div style="height:10px;"></div>

<!-- AI Hint -->
<div class="card" style="border-radius:18px;">
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <button type="button" class="badge" id="btnAiHint">✨ AI Hint</button>
    <div class="muted">Get guidance (no full solution code).</div>
  </div>

  <div class="ai-actions">
    <button type="button" class="badge" id="btnCopyHint" style="display:none;">Copy Hint</button>
  </div>

  <div id="aiHintBox" class="ai-card" style="display:none;"></div>
</div>

<div style="height:14px;"></div>

<?php
$sample_in  = $has_in  ? trim((string)($p["sample_input"] ?? "")) : "";
$sample_out = $has_out ? trim((string)($p["sample_output"] ?? "")) : "";
if ($sample_in !== "" || $sample_out !== ""):
?>
<div class="sample-grid">
  <div class="sample-card">
    <div class="sample-title">Sample Input</div>
    <pre class="sample-pre"><?= e($sample_in !== "" ? $sample_in : "(no input)") ?></pre>
  </div>
  <div class="sample-card">
    <div class="sample-title">Sample Output</div>
    <pre class="sample-pre"><?= e($sample_out !== "" ? $sample_out : "-") ?></pre>
  </div>
</div>
<?php endif; ?>

<div style="height:18px;"></div>

<?php if ($last): ?>
  <?php
    $last_code = (string)($last["code"] ?? "");
    $last_msg  = sanitize_judge_msg((string)($last["message"] ?? ""));
    $last_v    = strtoupper((string)($last["verdict"] ?? ""));
    $last_lang = strtoupper((string)($last["language"] ?? ""));
    $last_rt   = isset($last["runtime_ms"]) ? (int)$last["runtime_ms"] : null;

    $cc = "pill";
    if ($last_v === "AC") $cc .= " pill-ac";
    elseif ($last_v === "WA") $cc .= " pill-wa";
    elseif (in_array($last_v, ["CE","RE","TLE"], true)) $cc .= " pill-bad";
  ?>

  <div class="card" style="margin-bottom:14px;">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start;">
      <div>
        <div style="font-weight:900;">Last Submission</div>
        <div class="muted smallmono">Time: <?= e((string)($last["t"] ?? "")) ?></div>

        <div style="height:10px;"></div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <button type="button" class="badge"
            onclick="openModal('Judge Message', document.getElementById('last_msg_raw').textContent)">
            Judge Message
          </button>

          <button type="button" class="badge"
            onclick="openModal('Last Code', document.getElementById('last_code_raw').textContent)">
            Last Code
          </button>
        </div>

        <div style="height:12px;"></div>

        <!-- AI Feedback -->
        <button type="button" class="badge" id="btnAiFeedback">🧠 Explain my verdict (AI)</button>
        <div class="ai-actions">
          <button type="button" class="badge" id="btnCopyFeedback" style="display:none;">Copy Feedback</button>
        </div>
        <div id="aiFeedbackBox" class="ai-card" style="display:none;"></div>

        <div style="height:10px;"></div>
      </div>

      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <?php if ($last_lang !== ""): ?>
          <span class="pill"><?= e($last_lang) ?></span>
        <?php endif; ?>

        <?php if ($last_v !== ""): ?>
          <span class="<?= $cc ?>"><?= e($last_v) ?></span>
        <?php endif; ?>

        <?php if ($last_rt !== null): ?>
          <span class="muted smallmono"><?= (int)$last_rt ?> ms</span>
        <?php endif; ?>
      </div>
    </div>

    <pre id="last_code_raw" style="display:none;"><?= e($last_code) ?></pre>
    <pre id="last_msg_raw" style="display:none;"><?= e($last_msg !== "" ? $last_msg : "-") ?></pre>
  </div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-bottom:6px;">Submit Answer</h3>

  <div style="height:12px;"></div>

  <form method="POST">
    <?php if ($ctx_ok && $has_src_course && $has_src_section): ?>
      <!-- ✅ Option-2 tagging hidden fields -->
      <input type="hidden" name="source_course_id" value="<?= (int)$ctx_course_id ?>">
      <input type="hidden" name="source_section_id" value="<?= (int)$ctx_section_id ?>">
    <?php endif; ?>

    <label class="label">Language</label>
    <select name="language" required>
      <option value="c">C</option>
      <option value="cpp">C++</option>
    </select>

    <div style="height:12px;"></div>

    <label class="label">Code</label>
    <textarea id="codeEditor" name="answer" rows="12" placeholder="Write your C / C++ code here..." style="width:100%;" required><?= e($prefill_code) ?></textarea>

    <div style="height:12px;"></div>

    <!-- AI Code Check (Gemma) — works BEFORE you submit -->
    <button type="button" class="badge" id="btnAiCodeCheck">✅ AI Code Check (Gemma)</button>
    <div class="ai-actions">
      <button type="button" class="badge" id="btnCopyCodeCheck" style="display:none;">Copy Code Check</button>
    </div>
    <div id="aiCodeCheckBox" class="ai-card" style="display:none;"></div>

    <div style="height:12px;"></div>
    <button class="btn-primary" type="submit">Submit</button>
  </form>
</div>

<!-- Modal HTML -->
<div id="modalBackdrop" class="modal-backdrop" onclick="closeModal()">
  <div class="modal-box" onclick="event.stopPropagation();">
    <div class="modal-head">
      <div id="modalTitle" class="modal-title">Popup</div>

      <div class="modal-actions">
        <button id="modalCopyBtn" class="badge" type="button" onclick="copyModalContent(this)">Copy</button>
        <button class="modal-close" type="button" onclick="closeModal()">Close</button>
      </div>
    </div>
    <div class="modal-body">
      <pre id="modalContent" class="modal-pre"></pre>
    </div>
  </div>
</div>

<script>
/* -------- Modal helpers -------- */
function openModal(title, content){
  document.getElementById('modalTitle').textContent = title || 'Popup';
  document.getElementById('modalContent').textContent = content || '-';
  document.getElementById('modalBackdrop').style.display = 'flex';

  const btn = document.getElementById('modalCopyBtn');
  if(btn){
    btn.textContent = "Copy";
    setTimeout(()=> btn.focus(), 10);
  }
}
function closeModal(){
  document.getElementById('modalBackdrop').style.display = 'none';
}
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape') closeModal();
});

async function copyText(text){
  try{
    if(navigator.clipboard && window.isSecureContext){
      await navigator.clipboard.writeText(text);
      return true;
    }
  }catch(e){}

  try{
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.setAttribute("readonly", "");
    ta.style.position = "fixed";
    ta.style.left = "-9999px";
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand("copy");
    document.body.removeChild(ta);
    return ok;
  }catch(e){
    return false;
  }
}

async function copyModalContent(btn){
  const text = (document.getElementById('modalContent')?.textContent || "").trim();
  if(!text) return;

  const ok = await copyText(text);
  if(btn){
    btn.textContent = ok ? "Copied!" : "Copy Failed";
    setTimeout(()=> btn.textContent = "Copy", 900);
  }
}

/* -------- AI helpers -------- */
function stripThink(text){
  if(!text) return "";
  return String(text).replace(/<think>[\s\S]*?<\/think>/gi, "").trim();
}

function renderAiBox(box, title, text, meta){
  const clean = stripThink(text || "");
  box.innerHTML = `
    <div class="ai-title">${title}</div>
    <pre class="ai-pre">${escapeHtml(clean || "(empty)")}</pre>
    ${meta ? `<div class="ai-meta">${escapeHtml(meta)}</div>` : ``}
  `;
}

function renderAiError(box, msg){
  box.innerHTML = `<div class="ai-title">Error</div><div class="ai-error">${escapeHtml(msg || "AI error")}</div>`;
}

function escapeHtml(s){
  return (s||"").replace(/[&<>"']/g, m => ({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
  })[m]);
}

async function postJson(url, fd){
  const r = await fetch(url, { method:"POST", body: fd });
  const t = await r.text();
  try{
    return { ok:true, json: JSON.parse(t) };
  }catch(e){
    return { ok:false, raw: t };
  }
}

// ✅ loading helper (new)
function setBtnLoading(btn, on, label){
  if(!btn) return;
  if(!btn.dataset.label) btn.dataset.label = btn.innerHTML;
  btn.disabled = !!on;
  btn.classList.toggle("loading", !!on);
  if(on){
    btn.innerHTML = `<span class="spinner"></span>${label || "Loading..."}`;
  }else{
    btn.innerHTML = btn.dataset.label;
  }
}

/* -------- AI Hint (UPGRADED: cache + spinner + offline fallback) -------- */
(function(){
  const btn = document.getElementById("btnAiHint");
  const box = document.getElementById("aiHintBox");
  const btnCopy = document.getElementById("btnCopyHint");
  if(!btn || !box) return;

  const pid = "<?= (int)$pid ?>";
  const cacheKey = "ai_hint_" + pid;

  // instant cache (client-side)
  let cachedHint = null;
  try{ cachedHint = sessionStorage.getItem(cacheKey); }catch(e){}

  function showCopy(){
    if(btnCopy){
      btnCopy.style.display = "inline-block";
      btnCopy.onclick = async () => {
        const text = (box.textContent || "").trim();
        const ok = await copyText(text);
        btnCopy.textContent = ok ? "Copied!" : "Copy failed";
        setTimeout(()=> btnCopy.textContent = "Copy Hint", 900);
      };
    }
  }

  btn.addEventListener("click", async () => {
    box.style.display = "block";
    btnCopy && (btnCopy.style.display = "none");

    if(cachedHint && cachedHint.trim()){
      renderAiBox(box, "Response", cachedHint, "Cached • instant");
      showCopy();
      return;
    }

    setBtnLoading(btn, true, "Generating...");
    box.textContent = "Thinking...";

    const fd = new FormData();
    fd.append("problem_id", pid);

    const res = await postJson("/uiu_brainnext/api/ai_hint.php", fd);
    setBtnLoading(btn, false);

    if(!res.ok){
      renderAiError(box, "Non-JSON response from server:\n\n" + res.raw);
      return;
    }

    const j = res.json;

    if(j.ok){
      const hint = (j.hint || "").toString();
      cachedHint = hint;
      try{ sessionStorage.setItem(cacheKey, hint); }catch(e){}

    const metaParts = [];
      if (j.model) metaParts.push("Model: " + j.model);
      // (optional) remove cached/fresh line to stop showing "fresh"
      if (typeof j.latency_ms !== "undefined") metaParts.push(String(j.latency_ms) + " ms");
      if (j.usage_limit) metaParts.push(`AI usage: ${j.usage_used}/${j.usage_limit}`);
      const meta = metaParts.length ? metaParts.join(" • ") : "";

      renderAiBox(box, "Response", hint, meta);
      showCopy();
    }else{
      if((j.error_code || "") === "OLLAMA_OFFLINE"){
        renderAiError(box,
          "AI Hint is temporarily unavailable (Ollama is offline).\n\n" +
          "Fix:\n" +
          "1) Open PowerShell\n" +
          "2) Run:  ollama serve\n" +
          "3) Refresh this page\n"
        );
      }else{
        renderAiError(box, (j.detail || j.error || "AI error"));
      }
    }
  });
})();

/* -------- AI Feedback (UPGRADED: spinner + offline fallback) -------- */
(function(){
  const btn = document.getElementById("btnAiFeedback");
  const box = document.getElementById("aiFeedbackBox");
  const btnCopy = document.getElementById("btnCopyFeedback");
  if(!btn || !box) return;

  btn.addEventListener("click", async () => {
    box.style.display = "block";
    btnCopy && (btnCopy.style.display = "none");
    box.textContent = "Analyzing your submission...";

    setBtnLoading(btn, true, "Analyzing...");

    const fd = new FormData();
    fd.append("submission_id", "<?= (int)($last["id"] ?? 0) ?>");

    const res = await postJson("/uiu_brainnext/api/ai_feedback.php", fd);
    setBtnLoading(btn, false);

    if(!res.ok){
      renderAiError(box, "Non-JSON response from server:\n\n" + res.raw);
      return;
    }

    const j = res.json;
    if(j.ok){
      const fb = (j.feedback || "").toString();

      const metaParts = [];
      if (j.model) metaParts.push("Model: " + j.model);
      // (optional) remove cached/fresh line
      if (typeof j.latency_ms !== "undefined") metaParts.push(String(j.latency_ms) + " ms");
      if (j.usage_limit) metaParts.push(`AI usage: ${j.usage_used}/${j.usage_limit}`);
      const meta = metaParts.length ? metaParts.join(" • ") : "";


      renderAiBox(box, "Response", fb, meta);

      if(btnCopy){
        btnCopy.style.display = "inline-block";
        btnCopy.onclick = async () => {
          const text = (box.textContent || "").trim();
          const ok = await copyText(text);
          btnCopy.textContent = ok ? "Copied!" : "Copy failed";
          setTimeout(()=> btnCopy.textContent = "Copy Feedback", 900);
        };
      }
    }else{
      if((j.error_code || "") === "OLLAMA_OFFLINE"){
        renderAiError(box,
          "AI Feedback is unavailable (Ollama is offline).\n\nRun:\nollama serve\n"
        );
      }else{
        renderAiError(box, (j.detail || j.error || "AI error"));
      }
    }
  });
})();

/* -------- AI Code Check (Gemma) (UPGRADED: spinner + offline fallback) -------- */
(function(){
  const btn = document.getElementById("btnAiCodeCheck");
  const box = document.getElementById("aiCodeCheckBox");
  const btnCopy = document.getElementById("btnCopyCodeCheck");
  if(!btn || !box) return;

  btn.addEventListener("click", async () => {
    box.style.display = "block";
    btnCopy && (btnCopy.style.display = "none");
    box.textContent = "Checking your code...";

    const langSel = document.querySelector('select[name="language"]');
    const codeTa  = document.getElementById('codeEditor');
    const lang = (langSel?.value || "c").toString();
    const code = (codeTa?.value || "").toString();

    if(!code.trim()){
      renderAiError(box, "Please write code first, then run AI Code Check.");
      return;
    }

    setBtnLoading(btn, true, "Checking...");

    const fd = new FormData();
    fd.append("problem_id", "<?= (int)$pid ?>");
    fd.append("language", lang);
    fd.append("code", code);

    const res = await postJson("/uiu_brainnext/api/ai_codecheck.php", fd);
    setBtnLoading(btn, false);

    if(!res.ok){
      renderAiError(box, "Non-JSON response from server:\n\n" + res.raw);
      return;
    }

    const j = res.json;
    if(j.ok){
      const r = j.result || {};
      const pretty =
        "Code correct: " + (r.code_correct || "No") + "\n" +
        "Logic correct: " + (r.logic_correct || "No") + "\n" +
        "Explanation of the error:\n- " + ((r.bullets || []).join("\n- ") || "-");

      const metaParts = [];
        if (j.model) metaParts.push("Model: " + j.model);
        // (optional) remove cached/fresh line
        if (typeof j.latency_ms !== "undefined") metaParts.push(String(j.latency_ms) + " ms");
        if (j.usage_limit) metaParts.push(`AI usage: ${j.usage_used}/${j.usage_limit}`);
        const meta = metaParts.length ? metaParts.join(" • ") : "";


      renderAiBox(box, "Response", pretty, meta);

      if(btnCopy){
        btnCopy.style.display = "inline-block";
        btnCopy.onclick = async () => {
          const text = (box.textContent || "").trim();
          const ok = await copyText(text);
          btnCopy.textContent = ok ? "Copied!" : "Copy failed";
          setTimeout(()=> btnCopy.textContent = "Copy Code Check", 900);
        };
      }
    }else{
      if((j.error_code || "") === "OLLAMA_OFFLINE"){
        renderAiError(box,
          "AI Code Check is unavailable (Ollama is offline).\n\nRun:\nollama serve\n"
        );
      }else{
        renderAiError(box, (j.detail || j.error || "AI error"));
      }
    }
  });
})();
</script>

<?php ui_end(); ?>





