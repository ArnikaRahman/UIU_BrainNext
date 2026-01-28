<?php
// Student auth + load student data (enrollments + TEST-only stats)
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/functions.php";

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!isset($_SESSION["user"]) || ($_SESSION["user"]["role"] ?? "") !== "student") {
  redirect("/uiu_brainnext/index.php");
}

$uid = (int)$_SESSION["user"]["id"];

/**
 * Helper: normalize trimester display
 * Supports:
 *  - "Fall" / "Spring" / "Summer"
 *  - "1/2/3" (legacy numeric)
 *  - "t0/t1..." (legacy)
 */
function trimester_name($triVal): string {
  $triRaw = trim((string)($triVal ?? ""));
  if ($triRaw === "") return "-";

  // If DB stores Spring/Summer/Fall (your case)
  $triNorm = ucfirst(strtolower($triRaw));
  if (in_array($triNorm, ["Spring", "Summer", "Fall"], true)) {
    return $triNorm;
  }

  // If DB stores 1/2/3
  if (ctype_digit($triRaw)) {
    $TRI = [1 => "Spring", 2 => "Summer", 3 => "Fall"];
    $tno = (int)$triRaw;
    return $TRI[$tno] ?? ("T" . $tno);
  }

  // If DB stores t0/t1/t2...
  if (preg_match('/^t(\d+)$/i', $triRaw, $m)) {
    $map = [0 => "Fall", 1 => "Spring", 2 => "Summer", 3 => "Fall"];
    $tno = (int)$m[1];
    return $map[$tno] ?? ("T" . $tno);
  }

  // Fallback: show whatever is stored
  return $triRaw;
}

/**
 * 1) Student enrollments (for dashboard modal/table)
 */
$stmtE = $conn->prepare("
  SELECT
    e.section_id,
    c.code AS course_code,
    c.title AS course_title,
    s.section_label,
    s.trimester,
    s.year,
    u.full_name AS teacher_name,
    u.username AS teacher_username
  FROM enrollments e
  JOIN sections s ON s.id = e.section_id
  JOIN courses c ON c.id = s.course_id
  LEFT JOIN users u ON u.id = s.teacher_id
  WHERE e.student_id = ?
  ORDER BY s.year DESC, s.trimester DESC, c.code ASC, s.section_label ASC
");
$stmtE->bind_param("i", $uid);
$stmtE->execute();
$resE = $stmtE->get_result();

$enrolled = [];
while ($r = $resE->fetch_assoc()) {
  $r["trimester_name"] = trimester_name($r["trimester"] ?? "");
  $enrolled[] = $r;
}
$_SESSION["student_enrollments"] = $enrolled;

/**
 * 2) PRACTICE submissions count (NO marks, just count if you want to show)
 *    Practice problems have NO score contribution.
 */
$stmtP = $conn->prepare("
  SELECT
    COUNT(*) AS practice_submissions,
    COUNT(DISTINCT problem_id) AS practice_solved
  FROM submissions
  WHERE user_id = ?
");
$stmtP->bind_param("i", $uid);
$stmtP->execute();
$practice = $stmtP->get_result()->fetch_assoc() ?: [];
$practice_submissions = (int)($practice["practice_submissions"] ?? 0);
$practice_solved = (int)($practice["practice_solved"] ?? 0);

/**
 * 3) TEST stats (ONLY score used for leaderboard)
 */
$stmtT = $conn->prepare("
  SELECT
    COUNT(*) AS total_test_submissions,
    SUM(CASE WHEN status='Submitted' THEN 1 ELSE 0 END) AS pending_test_submissions,
    SUM(CASE WHEN status='Checked' THEN 1 ELSE 0 END) AS checked_test_submissions,
    COALESCE(SUM(CASE WHEN status='Checked' AND score IS NOT NULL THEN score ELSE 0 END),0) AS total_test_score
  FROM test_submissions
  WHERE student_id = ?
");
$stmtT->bind_param("i", $uid);
$stmtT->execute();
$tstats = $stmtT->get_result()->fetch_assoc() ?: [];

$total_test_submissions   = (int)($tstats["total_test_submissions"] ?? 0);
$pending_test_submissions = (int)($tstats["pending_test_submissions"] ?? 0);
$checked_test_submissions = (int)($tstats["checked_test_submissions"] ?? 0);
$total_test_score         = (int)($tstats["total_test_score"] ?? 0);

/**
 * 4) Pending tests NOT submitted yet
 */
$stmtPT = $conn->prepare("
  SELECT COUNT(*) AS pending_tests_not_submitted
  FROM tests t
  JOIN enrollments e ON e.section_id = t.section_id AND e.student_id = ?
  LEFT JOIN test_submissions ts ON ts.test_id = t.id AND ts.student_id = ?
  WHERE ts.id IS NULL
");
$stmtPT->bind_param("ii", $uid, $uid);
$stmtPT->execute();
$pendingNotSubmitted = (int)(($stmtPT->get_result()->fetch_assoc() ?: [])["pending_tests_not_submitted"] ?? 0);

/**
 * 5) Leaderboard rank based ONLY on total_test_score
 *    rank = count(students with higher score) + 1
 */
$stmtAll = $conn->prepare("SELECT COUNT(*) AS total_students FROM users WHERE role='student'");
$stmtAll->execute();
$total_students = (int)(($stmtAll->get_result()->fetch_assoc() ?: [])["total_students"] ?? 0);

$stmtRank = $conn->prepare("
  SELECT COUNT(*) + 1 AS my_rank
  FROM (
    SELECT u.id,
           COALESCE(SUM(CASE WHEN ts.status='Checked' AND ts.score IS NOT NULL THEN ts.score ELSE 0 END),0) AS score_sum
    FROM users u
    LEFT JOIN test_submissions ts ON ts.student_id = u.id
    WHERE u.role='student'
    GROUP BY u.id
  ) x
  WHERE x.score_sum > ?
");
$stmtRank->bind_param("i", $total_test_score);
$stmtRank->execute();
$my_rank = (int)(($stmtRank->get_result()->fetch_assoc() ?: [])["my_rank"] ?? 0);

$_SESSION["student_stats"] = [
  // practice (no marks)
  "practice_submissions" => $practice_submissions,
  "practice_solved" => $practice_solved,

  // tests (marks)
  "total_test_submissions" => $total_test_submissions,
  "pending_test_submissions" => $pending_test_submissions,
  "checked_test_submissions" => $checked_test_submissions,
  "pending_tests_not_submitted" => $pendingNotSubmitted,
  "total_test_score" => $total_test_score,

  // leaderboard based on tests
  "rank" => $my_rank,
  "total_students" => $total_students
];


