<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$teacher_id = (int)($_SESSION["user"]["id"] ?? 0);

/* ---------- helpers ---------- */
function tri_label($v): string {
  $t = trim((string)$v);

  // Handle common numeric encodings
  if ($t === "1") return "Spring";
  if ($t === "2") return "Summer";
  if ($t === "3") return "Fall";

  // IMPORTANT: your DB is showing trimester as "0" for a Fall section
  if ($t === "0") return "Fall";

  // Handle text values
  if (strcasecmp($t, "spring") === 0) return "Spring";
  if (strcasecmp($t, "summer") === 0) return "Summer";
  if (strcasecmp($t, "fall") === 0)   return "Fall";

  return $t;
}

function tri_filter_values(string $tri): array {
  $tri = trim($tri);
  if ($tri === "") return [];

  $t = strtolower($tri);

  // We bind as strings because sections.trimester is VARCHAR
  if ($t === "spring") return ["Spring", "1"];
  if ($t === "summer") return ["Summer", "2"];

  // Fall must match: Fall / 3 / 0 (your DB has 0)
  if ($t === "fall")   return ["Fall", "3", "0"];

  return [$tri];
}

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
    WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
    LIMIT 1
  ");
  if (!$st) return false;
  $st->bind_param("sss", $db, $table, $col);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}

function stmt_bind(mysqli_stmt $stmt, string $types, array &$params): void {
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => $v) $bind[] = &$params[$k];
  call_user_func_array([$stmt, "bind_param"], $bind);
}

/* detect test_submissions columns */
$ts_score_col = db_has_col($conn, "test_submissions", "score") ? "score" : null;

/* accepted condition */
$accepted_sql = "0";
if (db_has_col($conn, "test_submissions", "verdict")) {
  $accepted_sql = "CASE WHEN ts.verdict IN ('Accepted','AC') THEN 1 ELSE 0 END";
} elseif (db_has_col($conn, "test_submissions", "status")) {
  $accepted_sql = "CASE WHEN ts.status IN ('Accepted','AC') THEN 1 ELSE 0 END";
} elseif (db_has_col($conn, "test_submissions", "is_accepted")) {
  $accepted_sql = "CASE WHEN ts.is_accepted=1 THEN 1 ELSE 0 END";
}

$avg_score_sql = $ts_score_col ? "ROUND(AVG(ts.`$ts_score_col`), 2)" : "NULL";

/* ---------- page ---------- */
ui_start("Section Performance", "Teacher Panel");
ui_top_actions([
  ["Dashboard", "/teacher/dashboard.php"],
  ["My Sections", "/teacher/teacher_sections.php"],
  ["3D Analytics", "/teacher/analytics3d.php"],
]);

/* ---------- filters ---------- */
$tri    = trim($_GET["tri"] ?? "");
$year   = trim($_GET["year"] ?? "");
$sec_id = trim($_GET["sec_id"] ?? ""); // section id

if ($year === "") $year = (string)date("Y");

/* dropdown years */
$years = [];
$st = $conn->prepare("SELECT DISTINCT year FROM sections WHERE teacher_id=? ORDER BY year DESC");
$st->bind_param("i", $teacher_id);
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $years[] = (string)$row["year"];

/* dropdown sections (WITH course name, NO year/trimester text) */
$sections_list = [];
$st = $conn->prepare("
  SELECT s.id, s.section_label, s.trimester, s.year, c.code, c.title
  FROM sections s
  JOIN courses c ON c.id = s.course_id
  WHERE s.teacher_id=?
  ORDER BY s.year DESC,
    CASE
      WHEN s.trimester IN ('Spring','1') THEN 1
      WHEN s.trimester IN ('Summer','2') THEN 2
      WHEN s.trimester IN ('Fall','3','0') THEN 3
      ELSE 9
    END,
    c.code ASC,
    s.section_label ASC
");
$st->bind_param("i", $teacher_id);
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $sections_list[] = $row;

/* ---------- main query ---------- */
$where  = " WHERE s.teacher_id = ? ";
$params = [$teacher_id];
$types  = "i";

if ($year !== "" && ctype_digit($year)) {
  $where .= " AND s.year = ? ";
  $params[] = (int)$year;
  $types .= "i";
}

if ($sec_id !== "" && ctype_digit($sec_id)) {
  $where .= " AND s.id = ? ";
  $params[] = (int)$sec_id;
  $types .= "i";
}

if ($tri !== "") {
  $vals = tri_filter_values($tri);
  if (!empty($vals)) {
    $placeholders = implode(",", array_fill(0, count($vals), "?"));
    $where .= " AND s.trimester IN ($placeholders) ";
    foreach ($vals as $v) {
      $params[] = (string)$v;
      $types .= "s";
    }
  }
}

$sql = "
  SELECT
    s.id,
    c.code AS course_code,
    c.title AS course_title,
    s.section_label,
    s.trimester,
    s.year,
    COUNT(DISTINCT e.student_id) AS total_students,
    COUNT(ts.id) AS attempts,
    SUM($accepted_sql) AS accepted_cnt,
    $avg_score_sql AS avg_score
  FROM sections s
  JOIN courses c ON c.id = s.course_id
  LEFT JOIN enrollments e ON e.section_id = s.id
  LEFT JOIN tests t ON t.section_id = s.id
  LEFT JOIN test_submissions ts ON ts.test_id = t.id
  $where
  GROUP BY s.id
  ORDER BY s.year DESC,
    CASE
      WHEN s.trimester IN ('Spring','1') THEN 1
      WHEN s.trimester IN ('Summer','2') THEN 2
      WHEN s.trimester IN ('Fall','3','0') THEN 3
      ELSE 9
    END,
    c.code ASC,
    s.section_label ASC
";

$st = $conn->prepare($sql);
stmt_bind($st, $types, $params);
$st->execute();
$rows = $st->get_result();
?>

<div class="card">
  <h3 style="margin-bottom:6px;">Section Performance Dashboard</h3>
  <div class="muted">Per section: total students, attempts, accepted %, average score (tests only).</div>

  <div style="height:12px;"></div>

  <form method="GET" style="display:grid; grid-template-columns: 1fr 1fr 1.6fr 220px; gap:10px; align-items:end;">
    <div>
      <label class="label">Trimester</label>
      <select name="tri">
        <option value="">All</option>
        <option value="Spring" <?= (strtolower($tri)==="spring") ? "selected" : "" ?>>Spring</option>
        <option value="Summer" <?= (strtolower($tri)==="summer") ? "selected" : "" ?>>Summer</option>
        <option value="Fall"   <?= (strtolower($tri)==="fall") ? "selected" : "" ?>>Fall</option>
      </select>
    </div>

    <div>
      <label class="label">Year</label>
      <select name="year">
        <?php if (empty($years)): ?>
          <option value="<?= e($year) ?>"><?= e($year) ?></option>
        <?php else: ?>
          <?php foreach ($years as $y): ?>
            <option value="<?= e($y) ?>" <?= ($year===$y) ? "selected" : "" ?>><?= e($y) ?></option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>

    <div>
      <label class="label">Section</label>
      <select name="sec_id">
        <option value="">All</option>
        <?php foreach ($sections_list as $s): ?>
          <?php
            // ✅ NO "0 2025" or any year/trimester text here
            $label = $s["code"] . " - " . $s["title"] . " • " . $s["section_label"];
          ?>
          <option value="<?= (int)$s["id"] ?>" <?= ($sec_id !== "" && (int)$sec_id === (int)$s["id"]) ? "selected" : "" ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <button class="btn-primary" type="submit" style="width:100%;">Filter</button>
    </div>
  </form>
</div>

<div style="height:14px;"></div>

<div class="card">
  <?php if (!$rows || $rows->num_rows === 0): ?>
    <div class="muted">No data found for this filter.</div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Course</th>
          <th>Section</th>
          <th>Trimester</th>
          <th>Students</th>
          <th>Attempts</th>
          <th>Accepted %</th>
          <th>Avg Score</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($r = $rows->fetch_assoc()): ?>
          <?php
            $attempts = (int)$r["attempts"];
            $acc_cnt  = (int)$r["accepted_cnt"];
            $acc_pct  = ($attempts > 0) ? round(($acc_cnt * 100.0) / $attempts, 2) : null;
            $avg_score = $r["avg_score"];
          ?>
          <tr>
            <td>
              <div style="font-weight:900;"><?= e($r["course_code"]) ?></div>
              <div class="muted"><?= e($r["course_title"]) ?></div>
            </td>
            <td style="font-weight:900;"><?= e($r["section_label"]) ?></td>
            <td><?= e(tri_label($r["trimester"])) ?> / <?= (int)$r["year"] ?></td>
            <td style="font-weight:900;"><?= (int)$r["total_students"] ?></td>
            <td style="font-weight:900;"><?= $attempts ?></td>
            <td style="font-weight:900;"><?= ($acc_pct === null ? "-" : e($acc_pct."%")) ?></td>
            <td style="font-weight:900;"><?= ($avg_score === null ? "-" : e($avg_score)) ?></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php ui_end(); ?>





