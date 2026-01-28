<?php
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auth_student.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* Only accept POST */
if ($_SERVER["REQUEST_METHOD"] !== "POST") redirect("/uiu_brainnext/student/problems.php");

/* Basic input */
$problem_id  = (int)($_POST["problem_id"] ?? 0);
$answer_text = trim((string)($_POST["answer_text"] ?? ""));

$user_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($user_id <= 0) redirect("/uiu_brainnext/logout.php");

if ($problem_id <= 0 || $answer_text === "") {
  set_flash("err", "Submission failed. Try again.");
  redirect("/uiu_brainnext/student/problems.php");
}

/* ---------- Backend safety checks ---------- */

/* 1) Ensure problem exists */
$stP = $conn->prepare("SELECT id FROM problems WHERE id = ? LIMIT 1");
if (!$stP) {
  set_flash("err", "Server error (prepare failed).");
  redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);
}
$stP->bind_param("i", $problem_id);
$stP->execute();
$rp = $stP->get_result();
if (!$rp || !$rp->fetch_row()) {
  $stP->close();
  set_flash("err", "Invalid problem.");
  redirect("/uiu_brainnext/student/problems.php");
}
$stP->close();

/* 2) Prevent duplicate submission if you want "only once" per problem per student
      (set $ALLOW_RESUBMIT=true if you want multiple attempts) */
$ALLOW_RESUBMIT = true; // change to false to enforce single submission only

if (!$ALLOW_RESUBMIT) {
  $stDup = $conn->prepare("SELECT id FROM submissions WHERE user_id = ? AND problem_id = ? LIMIT 1");
  if ($stDup) {
    $stDup->bind_param("ii", $user_id, $problem_id);
    $stDup->execute();
    $rd = $stDup->get_result();
    if ($rd && $rd->fetch_assoc()) {
      $stDup->close();
      set_flash("err", "You already submitted this problem. Re-submission is not allowed.");
      redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);
    }
    $stDup->close();
  }
}

/* 3) Protect DB from huge payload */
$MAX_LEN = 20000; // 20k chars (adjust as needed)
if (strlen($answer_text) > $MAX_LEN) {
  set_flash("err", "Submission is too long. Please shorten it.");
  redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);
}

/* 4) Use transaction for safer insert */
$conn->begin_transaction();

try {
  /* If your submissions table has these columns, this will work.
     If not, it still inserts into existing columns only. */
  $has_status = false;
  $has_created_at = false;

  $chk = $conn->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'submissions'
      AND COLUMN_NAME IN ('status','created_at','submitted_at')
  ");
  if ($chk) {
    $chk->execute();
    $res = $chk->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
      if ($row["COLUMN_NAME"] === "status") $has_status = true;
      if ($row["COLUMN_NAME"] === "created_at" || $row["COLUMN_NAME"] === "submitted_at") $has_created_at = true;
    }
    $chk->close();
  }

  if ($has_status && $has_created_at) {
    // try created_at first; if not present it will just be handled by default timestamp
    $stmt = $conn->prepare("
      INSERT INTO submissions (user_id, problem_id, answer_text, status, created_at)
      VALUES (?, ?, ?, 'Pending', NOW())
    ");
  } elseif ($has_status) {
    $stmt = $conn->prepare("
      INSERT INTO submissions (user_id, problem_id, answer_text, status)
      VALUES (?, ?, ?, 'Pending')
    ");
  } else {
    $stmt = $conn->prepare("
      INSERT INTO submissions (user_id, problem_id, answer_text)
      VALUES (?, ?, ?)
    ");
  }

  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }

  $stmt->bind_param("iis", $user_id, $problem_id, $answer_text);

  if (!$stmt->execute()) {
    // Handle duplicate key if you added UNIQUE(user_id, problem_id)
    if (($conn->errno ?? 0) === 1062) {
      throw new Exception("Duplicate submission.");
    }
    throw new Exception("Execute failed: " . $conn->error);
  }

  $stmt->close();
  $conn->commit();

  set_flash("ok", "Submitted! Status: Pending");
  redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);

} catch (Throwable $e) {
  $conn->rollback();

  $msg = $e->getMessage();
  if ($msg === "Duplicate submission.") {
    set_flash("err", "You already submitted this problem. Re-submission is not allowed.");
  } else {
    // Do not leak internal errors to user in production; keep it simple
    set_flash("err", "Could not submit. Please try again.");
  }

  redirect("/uiu_brainnext/student/problem_view.php?id=" . $problem_id);
}

