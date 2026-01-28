<?php
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/auth_student.php";

$action = strtolower(trim($_POST["action"] ?? "sync")); // sync|clear
$handle = trim($_POST["handle"] ?? "");

$user_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($user_id <= 0) {
  echo json_encode(["ok"=>false,"error"=>"Not logged in"]);
  exit;
}

// clear cache only
if ($action === "clear" || $handle === "") {
  $conn->query("DELETE FROM cf_user_solved WHERE user_id=$user_id");
  echo json_encode(["ok"=>true,"cleared"=>true]);
  exit;
}
$url = "https://codeforces.com/api/user.status?handle=" . urlencode($handle);

$data = json_decode(file_get_contents($url), true);
if (!$data || $data["status"] !== "OK") {
  echo json_encode(["ok"=>false,"error"=>"Invalid handle"]);
  exit;
}

$conn->query("DELETE FROM cf_user_solved WHERE user_id=$user_id");

foreach ($data["result"] as $s) {
  if ($s["verdict"] !== "OK") continue;
  if (!isset($s["problem"]["contestId"])) continue;

  $cid = (int)$s["problem"]["contestId"];
  $idx = (string)$s["problem"]["index"];

  $st = $conn->prepare("SELECT id FROM cf_problems WHERE contest_id=? AND problem_index=? LIMIT 1");
  if (!$st) continue;
  $st->bind_param("is", $cid, $idx);
  $st->execute();
  $r = $st->get_result();
  if ($r && ($row = $r->fetch_assoc())) {
    $pid = (int)$row["id"];
    $conn->query("INSERT IGNORE INTO cf_user_solved(user_id, problem_id) VALUES ($user_id,$pid)");
  }
  $st->close();
}

echo json_encode(["ok"=>true]);
