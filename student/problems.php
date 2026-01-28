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
function db_has_table(mysqli $conn, string $table): bool {
  $db = db_current_name($conn);
  if ($db === "") return false;

  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME   = ?
    LIMIT 1
  ");
  if (!$st) return false;

  $st->bind_param("ss", $db, $table);
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
function fmt_lang($raw): string {
  $x = strtolower(trim((string)$raw));
  if ($x === "cpp") return "C++";
  if ($x === "c") return "C";
  if ($x === "") return "";
  return strtoupper($x);
}

/* ---------------- CF API helper (for handle solved mapping) ---------------- */
function cf_api_get_json(string $url): array {
  $ctx = stream_context_create([
    "http" => [
      "timeout" => 25,
      "header" => "User-Agent: UIUBrainNext/1.0\r\n"
    ]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) return ["status" => "FAILED", "comment" => "Request failed"];
  $json = json_decode($raw, true);
  return is_array($json) ? $json : ["status" => "FAILED", "comment" => "Bad JSON"];
}

/* ---------------- user + schema ---------------- */
$user_id = (int)($_SESSION["user"]["id"] ?? 0);
if ($user_id <= 0) redirect("/uiu_brainnext/logout.php");

$sub_user_col = db_has_col($conn, "submissions", "user_id") ? "user_id" : (db_has_col($conn, "submissions", "student_id") ? "student_id" : "user_id");
$sub_time_col = db_has_col($conn, "submissions", "submitted_at") ? "submitted_at" : (db_has_col($conn, "submissions", "created_at") ? "created_at" : "submitted_at");

$has_verdict = db_has_col($conn, "submissions", "verdict");
$has_score = db_has_col($conn, "submissions", "score");
$has_lang = db_has_col($conn, "submissions", "language");
$has_short = db_has_col($conn, "problems", "short_title");
$hasEnroll = !empty($_SESSION["student_enrollments"] ?? []);
$has_cf = db_has_table($conn, "cf_problems");

// cf_user_solved cache (per UIU user_id)
$has_cf_user_solved = db_has_table($conn, "cf_user_solved")
  && db_has_col($conn, "cf_user_solved", "user_id")
  && db_has_col($conn, "cf_user_solved", "problem_id");

/* CF tag schema detection (VERY IMPORTANT) */
$has_cf_tags_tables = db_has_table($conn, "cf_tags") && db_has_table($conn, "cf_problem_tags");

/* cf_tags can have: id + name OR id + tag OR only tag-like column
   cf_problem_tags can have: problem_id + tag_id OR problem_id + tag */
$cf_tag_name_col = null; // in cf_tags: name OR tag
if ($has_cf_tags_tables) {
  if (db_has_col($conn, "cf_tags", "name")) $cf_tag_name_col = "name";
  else if (db_has_col($conn, "cf_tags", "tag")) $cf_tag_name_col = "tag";
}
$cf_tags_has_id = $has_cf_tags_tables && db_has_col($conn, "cf_tags", "id");
$cf_pt_has_problem_id = $has_cf_tags_tables && db_has_col($conn, "cf_problem_tags", "problem_id");
$cf_pt_has_tag_id = $has_cf_tags_tables && db_has_col($conn, "cf_problem_tags", "tag_id");
$cf_pt_has_tag_str = $has_cf_tags_tables && db_has_col($conn, "cf_problem_tags", "tag");

$can_filter_tags_by_id = ($cf_tag_name_col && $cf_tags_has_id && $cf_pt_has_problem_id && $cf_pt_has_tag_id);
$can_filter_tags_by_str = ($cf_tag_name_col && $cf_pt_has_problem_id && $cf_pt_has_tag_str);

$cf_tags_enabled = $has_cf_tags_tables && $cf_tag_name_col && ($can_filter_tags_by_id || $can_filter_tags_by_str);

/* ---------------- solved map (UIU only) ---------------- */
$stats = [];
if ($has_verdict) {
  $select = " problem_id,
              MAX(CASE WHEN UPPER(verdict)='AC' THEN 1 ELSE 0 END) AS solved,
              SUBSTRING_INDEX(GROUP_CONCAT(verdict ORDER BY id DESC SEPARATOR ','), ',', 1) AS last_verdict,
              MAX($sub_time_col) AS last_time ";
  if ($has_score) $select .= ", MAX(score) AS best_score";
  if ($has_lang)  $select .= ", SUBSTRING_INDEX(GROUP_CONCAT(language ORDER BY id DESC SEPARATOR ','), ',', 1) AS last_lang";

  $st = $conn->prepare("SELECT $select FROM submissions WHERE $sub_user_col = ? GROUP BY problem_id");
  $st->bind_param("i", $user_id);
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

/* ---------------- filters ---------------- */
$type = strtolower(trim((string)($_GET["type"] ?? "uiu"))); // uiu / cf
if (!in_array($type, ["uiu","cf"], true)) $type = "uiu";

$q = trim((string)($_GET["q"] ?? ""));
$diff = trim((string)($_GET["diff"] ?? "")); // UIU difficulty
$show = trim((string)($_GET["show"] ?? "all")); // all/solved/unsolved (UIU only)

$cf_min = (int)($_GET["cf_min"] ?? 0);
$cf_max = (int)($_GET["cf_max"] ?? 0);
$cf_contest_id = trim((string)($_GET["cf_contest_id"] ?? ""));
$cf_index = trim((string)($_GET["cf_index"] ?? ""));

$cf_handle = trim((string)($_GET["cf_handle"] ?? ""));
$cf_fetch = (int)($_GET["cf_fetch"] ?? 0);
$cf_clear = (int)($_GET["cf_clear"] ?? 0);
$cf_verified = (int)($_GET["cf_verified"] ?? 0);

$cf_show = strtolower(trim((string)($_GET["cf_show"] ?? "all"))); // all/solved/unsolved (CF only, after verified)
if (!in_array($cf_show, ["all","solved","unsolved"], true)) $cf_show = "all";

$cf_hide_tags = (int)($_GET["cf_hide_tags"] ?? 0); // 1 = hide tags pills in CF rows (after verified)
$cf_handle_ok = false;

// Backward-compatible aliases used by some template blocks (feature disabled)
$cf_show_solved_only = 0;
$cf_show_unsolved_only = 0;
$cf_hide_tags_unsolved = 0;

// Backward-compatible aliases used by some template blocks
$cf_show_solved = 0;
$cf_show_unsolved = 0;

$cf_or = (int)($_GET["cf_or"] ?? 1); // 1=OR, 0=AND
$cf_tag_q = trim((string)($_GET["cf_tag_q"] ?? ""));

$cf_tags = $_GET["cf_tags"] ?? [];
if (!is_array($cf_tags)) $cf_tags = [];
$cf_tags_raw = $cf_tags;

$sort = strtolower(trim((string)($_GET["sort"] ?? "")));
$page = clamp_int($_GET["page"] ?? 1, 1, 999999);
$per  = clamp_int($_GET["per"] ?? 15, 5, 50);
$offset = ($page - 1) * $per;

/* ---------------- CF handle cache + fetch/clear ---------------- */
$cf_handle = strtolower(trim((string)$cf_handle));
$cf_handle = preg_replace('/\s+/', '', $cf_handle);

if (!isset($_SESSION["cf_handle_cache"]) || !is_array($_SESSION["cf_handle_cache"])) {
  $_SESSION["cf_handle_cache"] = [];
}
$cf_cache =& $_SESSION["cf_handle_cache"];

// mark handle as verified after successful Fetch (even if solved list is 0)
if ($type === "cf" && (int)$cf_verified === 1 && $cf_handle !== "") {
  if (!isset($cf_cache[$cf_handle]) || !is_array($cf_cache[$cf_handle])) $cf_cache[$cf_handle] = [];
  if (!isset($cf_cache[$cf_handle]["keys"]) || !is_array($cf_cache[$cf_handle]["keys"])) $cf_cache[$cf_handle]["keys"] = [];
  $cf_cache[$cf_handle]["verified"] = 1;

  $qs = $_GET;
  unset($qs["cf_verified"]);
  redirect("/uiu_brainnext/student/problems.php?" . http_build_query($qs));
}

// clear handle cache (and input)
if ($type === "cf" && (int)$cf_clear === 1) {
  if ($cf_handle !== "" && isset($cf_cache[$cf_handle])) unset($cf_cache[$cf_handle]);
  $qs = $_GET;
  unset($qs["cf_clear"], $qs["cf_fetch"]);
  $qs["cf_handle"] = "";
  $qs["page"] = 1;
  redirect("/uiu_brainnext/student/problems.php?" . http_build_query($qs));
}

// fetch solved set from Codeforces API
if ($type === "cf" && (int)$cf_fetch === 1 && $cf_handle !== "") {
  $api = cf_api_get_json("https://codeforces.com/api/user.status?handle=" . rawurlencode($cf_handle) . "&from=1&count=10000");
  if (($api["status"] ?? "") === "OK") {
    $keys = [];
    $subs = $api["result"] ?? [];
    foreach ($subs as $sub) {
      if (($sub["verdict"] ?? "") !== "OK") continue;
      $p = $sub["problem"] ?? [];
      $cid = (int)($p["contestId"] ?? $sub["contestId"] ?? 0);
      $pidx = (string)($p["index"] ?? "");
      if ($cid <= 0 || $pidx === "") continue;
      $keys[$cid . "_" . $pidx] = 1;
    }
    $cf_cache[$cf_handle] = ["ts" => time(), "keys" => $keys, "verified" => 1];
    set_flash("ok", "Fetched Codeforces solved list for @" . $cf_handle . " (" . count($keys) . " solved).");
  } else {
    $msg = (string)($api["comment"] ?? "Unknown error");
    set_flash("err", "Codeforces API error: " . $msg);
    unset($cf_cache[$cf_handle]);
  }
  $qs = $_GET;
  unset($qs["cf_fetch"]);
  $qs["page"] = 1;
  $qs["cf_handle"] = $cf_handle;
  redirect("/uiu_brainnext/student/problems.php?" . http_build_query($qs));
}

$cf_handle_ok = ($type === "cf" && $cf_handle !== "" && isset($cf_cache[$cf_handle]["verified"]) && (int)$cf_cache[$cf_handle]["verified"] === 1);
$cf_solved_keys = ($cf_handle_ok && isset($cf_cache[$cf_handle]["keys"]) && is_array($cf_cache[$cf_handle]["keys"])) ? $cf_cache[$cf_handle]["keys"] : [];

/* ---------------- UI top actions ---------------- */
ui_start("Practice Problems", "Student Panel");

$actions = [
  ["Dashboard", "/student/dashboard.php"],
  ["Tests", "/student/student_tests.php"],
  ["My Submissions", "/student/submissions.php"],
];
if ($hasEnroll) $actions[] = ["Course Practice", "/student/course_practice.php"];
ui_top_actions($actions);

/* ---------------- fetch CF tags for dropdown ---------------- */
$all_cf_tags = [];
if ($cf_tags_enabled) {
  if ($can_filter_tags_by_id) {
    $sqlTags = "SELECT t.id, t.$cf_tag_name_col AS tagname
                FROM cf_tags t
                ORDER BY t.$cf_tag_name_col ASC
                LIMIT 300";
    $stT = $conn->prepare($sqlTags);
    $stT->execute();
    $rsT = $stT->get_result();
    while ($rsT && ($row = $rsT->fetch_assoc())) $all_cf_tags[] = $row;

    // selected ids normalize
    $cf_tags = array_values(array_filter(array_map("intval", $cf_tags_raw), fn($x)=>$x>0));

  } else {
    // ✅ string-tag mode: prefer cf_tags, but fallback to cf_problem_tags if cf_tags empty
    $sqlTags = "SELECT DISTINCT t.$cf_tag_name_col AS tagname
                FROM cf_tags t
                ORDER BY t.$cf_tag_name_col ASC
                LIMIT 300";
    $stT = $conn->prepare($sqlTags);
    $stT->execute();
    $rsT = $stT->get_result();
    while ($rsT && ($row = $rsT->fetch_assoc())) {
      $all_cf_tags[] = ["tagname" => (string)$row["tagname"]];
    }

    // ✅ fallback: if cf_tags empty, load from cf_problem_tags.tag
    if (empty($all_cf_tags) && $cf_pt_has_tag_str) {
      $sqlTags2 = "SELECT DISTINCT pt.tag AS tagname
                   FROM cf_problem_tags pt
                   WHERE pt.tag IS NOT NULL AND pt.tag <> ''
                   ORDER BY pt.tag ASC
                   LIMIT 300";
      $stT2 = $conn->prepare($sqlTags2);
      $stT2->execute();
      $rsT2 = $stT2->get_result();
      while ($rsT2 && ($row = $rsT2->fetch_assoc())) {
        $all_cf_tags[] = ["tagname" => (string)$row["tagname"]];
      }
    }

    // selected strings normalize
    $cf_tags = array_values(array_filter(
      array_map(fn($s)=>trim((string)$s), $cf_tags_raw),
      fn($x)=>$x!==""
    ));
  }
} else {
  $cf_tags = [];
}

$selected_cf_tag_names = [];
if ($cf_tags_enabled && !empty($cf_tags)) {
  if ($can_filter_tags_by_id) {
    $idToName = [];
    foreach ($all_cf_tags as $t) {
      $idToName[(int)($t["id"] ?? 0)] = (string)($t["tagname"] ?? "");
    }
    foreach ($cf_tags as $tid) {
      $nm = (string)($idToName[(int)$tid] ?? "");
      if ($nm !== "") $selected_cf_tag_names[] = $nm;
    }
  } else {
    foreach ($cf_tags as $nm) {
      $nm = trim((string)$nm);
      if ($nm !== "") $selected_cf_tag_names[] = $nm;
    }
  }
}

/* ---------------- data queries ---------------- */
$rows = [];
$total = 0;
$cf_tags_by_pid = []; // [problem_id] => [tag, tag, ...]

$use_db_solved = ($type === "cf" && $cf_handle_ok && $has_cf_user_solved);

if ($type === "cf") {
  if (!$has_cf) {
    $rows = [];
    $total = 0;
  } else {
    $where = " WHERE 1=1 ";
    $params = [];
    $types = "";

    $joinSolved = "";
    $solvedSelect = "0 AS _solved";
    if ($use_db_solved) {
      $joinSolved = " LEFT JOIN cf_user_solved us ON us.problem_id = p.id AND us.user_id = ? ";
      $params[] = $user_id;
      $types .= "i";
      $solvedSelect = "CASE WHEN us.problem_id IS NULL THEN 0 ELSE 1 END AS _solved";
    }

    if ($q !== "") {
      $where .= " AND (p.title LIKE ? OR CAST(p.contest_id AS CHAR) LIKE ? OR CONCAT(p.contest_id, p.problem_index) LIKE ?) ";
      $like = "%".$q."%";
      $params[] = $like; $params[] = $like; $params[] = $like;
      $types .= "sss";
    }
    if ($cf_contest_id !== "") {
      if (ctype_digit($cf_contest_id)) {
        $where .= " AND p.contest_id = ? ";
        $params[] = (int)$cf_contest_id;
        $types .= "i";
      } else {
        $where .= " AND CAST(p.contest_id AS CHAR) LIKE ? ";
        $params[] = "%".$cf_contest_id."%";
        $types .= "s";
      }
    }
    if ($cf_index !== "") {
      $where .= " AND UPPER(p.problem_index) = ? ";
      $params[] = strtoupper($cf_index);
      $types .= "s";
    }
    if ($cf_min > 0) {
      $where .= " AND p.rating IS NOT NULL AND p.rating >= ? ";
      $params[] = $cf_min;
      $types .= "i";
    }
    if ($cf_max > 0) {
      $where .= " AND p.rating IS NOT NULL AND p.rating <= ? ";
      $params[] = $cf_max;
      $types .= "i";
    }

    if ($cf_handle_ok && $cf_show !== "all") {
      if ($use_db_solved) {
        if ($cf_show === "solved") $where .= " AND us.problem_id IS NOT NULL ";
        if ($cf_show === "unsolved") $where .= " AND us.problem_id IS NULL ";
      }
    }

    $joinTags = "";
    $having = "";
    if ($cf_tags_enabled && count($cf_tags) > 0) {
      $joinTags = " JOIN cf_problem_tags pt ON pt.problem_id = p.id ";
      if ($can_filter_tags_by_id) {
        $in = implode(",", array_fill(0, count($cf_tags), "?"));
        $where .= " AND pt.tag_id IN ($in) ";
        foreach ($cf_tags as $tid) { $params[] = (int)$tid; $types .= "i"; }
        if ((int)$cf_or === 0) $having = " HAVING COUNT(DISTINCT pt.tag_id) >= " . (int)count($cf_tags) . " ";
      } else if ($can_filter_tags_by_str) {
        $in = implode(",", array_fill(0, count($cf_tags), "?"));
        $where .= " AND pt.tag IN ($in) ";
        foreach ($cf_tags as $tg) { $params[] = $tg; $types .= "s"; }
        if ((int)$cf_or === 0) $having = " HAVING COUNT(DISTINCT pt.tag) >= " . (int)count($cf_tags) . " ";
      }
    }

    $sqlCount = "SELECT COUNT(DISTINCT p.id) AS c FROM cf_problems p $joinSolved $joinTags $where";
    $stC = $conn->prepare($sqlCount);
    if ($types !== "") {
      $bind = [];
      $bind[] = $types;
      foreach ($params as $k => $v) $bind[] = &$params[$k];
      call_user_func_array([$stC, "bind_param"], $bind);
    }
    $stC->execute();
    $total = (int)($stC->get_result()->fetch_assoc()["c"] ?? 0);

    $order = " ORDER BY p.contest_id DESC, p.problem_index DESC ";
    if ($sort === "rating_asc")  $order = " ORDER BY (p.rating IS NULL) ASC, p.rating ASC, p.solved_count DESC ";
    if ($sort === "rating_desc") $order = " ORDER BY (p.rating IS NULL) ASC, p.rating DESC, p.solved_count DESC ";
    if ($sort === "solved_desc") $order = " ORDER BY p.solved_count DESC, (p.rating IS NULL) ASC, p.rating DESC ";

    $sql = "SELECT p.id, p.contest_id, p.problem_index, p.title, p.rating, p.solved_count, $solvedSelect
            FROM cf_problems p
            $joinSolved $joinTags
            $where
            GROUP BY p.id
            $having
            $order
            LIMIT ? OFFSET ?";

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

    $rs = $st->get_result();
    while ($rs && ($row = $rs->fetch_assoc())) {
      if ($cf_handle_ok) {
        if ($use_db_solved) {
          $row["_is_solved"] = ((int)($row["_solved"] ?? 0) === 1) ? 1 : 0;
        } else {
          $k = (string)($row["contest_id"] ?? "") . "_" . (string)($row["problem_index"] ?? "");
          $row["_is_solved"] = isset($cf_solved_keys[$k]) ? 1 : 0;
        }
      } else {
        $row["_is_solved"] = 0;
      }
      $row["_src"] = "cf";
      $rows[] = $row;
    }

    if ($cf_handle_ok && !$use_db_solved && $cf_show !== "all" && !empty($rows)) {
      $rows = array_values(array_filter($rows, function($r) use ($cf_show) {
        $s = (int)($r["_is_solved"] ?? 0);
        if ($cf_show === "solved") return $s === 1;
        if ($cf_show === "unsolved") return $s === 0;
        return true;
      }));
    }

    if ($cf_tags_enabled && !empty($rows)) {
      $ids = array_values(array_unique(array_map(fn($x) => (int)($x["id"] ?? 0), $rows)));
      $ids = array_values(array_filter($ids, fn($x) => $x > 0));
      if (!empty($ids)) {
        $in = implode(",", array_fill(0, count($ids), "?"));

        if ($can_filter_tags_by_id) {
          $sqlT = "SELECT pt.problem_id, t.$cf_tag_name_col AS tagname
                   FROM cf_problem_tags pt
                   JOIN cf_tags t ON t.id = pt.tag_id
                   WHERE pt.problem_id IN ($in)
                   ORDER BY t.$cf_tag_name_col ASC";
          $stTT = $conn->prepare($sqlT);
          $typesT = str_repeat("i", count($ids));
          $bindT = [$typesT];
          foreach ($ids as $k => $v) $bindT[] = &$ids[$k];
          call_user_func_array([$stTT, "bind_param"], $bindT);
          $stTT->execute();
          $rt = $stTT->get_result();
          while ($rt && ($tr = $rt->fetch_assoc())) {
            $pid = (int)($tr["problem_id"] ?? 0);
            $tg  = (string)($tr["tagname"] ?? "");
            if ($pid <= 0 || $tg === "") continue;
            $cf_tags_by_pid[$pid][] = $tg;
          }
        } else if ($can_filter_tags_by_str) {
          $sqlT = "SELECT problem_id, tag AS tagname
                   FROM cf_problem_tags
                   WHERE problem_id IN ($in)
                   ORDER BY tag ASC";
          $stTT = $conn->prepare($sqlT);
          $typesT = str_repeat("i", count($ids));
          $bindT = [$typesT];
          foreach ($ids as $k => $v) $bindT[] = &$ids[$k];
          call_user_func_array([$stTT, "bind_param"], $bindT);
          $stTT->execute();
          $rt = $stTT->get_result();
          while ($rt && ($tr = $rt->fetch_assoc())) {
            $pid = (int)($tr["problem_id"] ?? 0);
            $tg  = (string)($tr["tagname"] ?? "");
            if ($pid <= 0 || $tg === "") continue;
            $cf_tags_by_pid[$pid][] = $tg;
          }
        }
      }
    }
  }
} else {
  // UIU
  $where = " WHERE 1=1 ";
  $params = [];
  $types = "";

  if ($q !== "") {
    if ($has_short) {
      $where .= " AND (p.title LIKE ? OR p.short_title LIKE ? OR c.code LIKE ?) ";
      $like = "%".$q."%";
      array_push($params, $like, $like, $like);
      $types .= "sss";
    } else {
      $where .= " AND (p.title LIKE ? OR c.code LIKE ?) ";
      $like = "%".$q."%";
      array_push($params, $like, $like);
      $types .= "ss";
    }
  }

  if ($diff !== "" && in_array($diff, ["Easy","Medium","Hard"], true)) {
    $where .= " AND p.difficulty = ? ";
    $params[] = $diff;
    $types .= "s";
  }

  $sqlCount = "SELECT COUNT(*) c FROM problems p JOIN courses c ON c.id = p.course_id $where";
  $stc = $conn->prepare($sqlCount);
  if ($types !== "") {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array([$stc, "bind_param"], $bind);
  }
  $stc->execute();
  $total = (int)($stc->get_result()->fetch_assoc()["c"] ?? 0);

  $selectP = "p.id, p.course_id, p.title, p.difficulty, c.code AS course_code";
  if ($has_short) $selectP .= ", p.short_title";

  $order = " ORDER BY c.code ASC, p.id ASC ";
  if ($sort === "diff_asc")  $order = " ORDER BY FIELD(p.difficulty,'Easy','Medium','Hard') ASC, c.code ASC, p.id ASC ";
  if ($sort === "diff_desc") $order = " ORDER BY FIELD(p.difficulty,'Hard','Medium','Easy') ASC, c.code ASC, p.id ASC ";

  $sql = "SELECT $selectP
          FROM problems p
          JOIN courses c ON c.id = p.course_id
          $where
          $order
          LIMIT ? OFFSET ?";

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
  while ($resP && ($row = $resP->fetch_assoc())) {
    $row["_src"] = "uiu";
    $rows[] = $row;
  }

  if ($show === "solved" || $show === "unsolved") {
    $rows = array_values(array_filter($rows, function($r) use ($stats, $show) {
      $pid = (int)$r["id"];
      $solved = (int)($stats[$pid]["solved"] ?? 0);
      return $show === "solved" ? ($solved === 1) : ($solved === 0);
    }));
    $total = count($rows);
  }
}

/* ---------------- UI ---------------- */
?>
<style>
  .filters{ display:grid; grid-template-columns: repeat(12, minmax(0,1fr)); gap:10px; align-items:end; }
  @media(max-width: 1100px){ .filters{grid-template-columns: repeat(6, minmax(0,1fr));} }
  @media(max-width: 700px){ .filters{grid-template-columns: repeat(2, minmax(0,1fr));} }
  .fcol-4{grid-column: span 4;}
  .fcol-3{grid-column: span 3;}
  .fcol-2{grid-column: span 2;}
  .fcol-12{grid-column: span 12;}
  .filters select{width:100%; box-sizing:border-box;}
  .filters input:not([type=checkbox]):not([type=radio]){width:100%; box-sizing:border-box;}

  .pill{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:900;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);}
  .pill-ac{border-color:rgba(0,180,90,.35);background:rgba(0,180,90,.12);}
  .pill-wa{border-color:rgba(255,180,0,.35);background:rgba(255,180,0,.10);}
  .pill-bad{border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.10);}
  .pill-manual{border-color:rgba(120,160,255,.35);background:rgba(120,160,255,.10);}
  .smallmono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px;}
  .muted{opacity:.78;}

  .list-head{ display:grid; grid-template-columns: 1.6fr 0.75fr 0.95fr; gap:10px; align-items:center; padding:10px 10px; font-weight:900; opacity:.9; }
  .list-row{ display:grid; grid-template-columns: 1.6fr 0.75fr 0.95fr; gap:10px; align-items:center; padding:12px 12px; border-radius:18px; border:1px solid rgba(255,255,255,.08); background:rgba(10,15,25,.20); margin-bottom:10px; }
  .row-link{color:inherit;text-decoration:none;}
  .row-link:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.16);background:rgba(10,15,25,.28);}
  .row-link:focus{outline:2px solid rgba(80,140,255,.35);outline-offset:2px;}
  @media(max-width: 900px){ .list-head, .list-row{grid-template-columns: 1fr;} .hide-sm{display:none;} }

  .p-title{font-weight:900;font-size:16px;}
  .p-sub{margin-top:4px;}
  .kv{display:flex;gap:10px;align-items:center;justify-content:flex-start;flex-wrap:wrap;}

  .cf-badge{ display:inline-block;padding:6px 10px;border-radius:999px; border:1px solid rgba(80,140,255,.30); background:rgba(80,140,255,.12); font-weight:900; }
  .uiu-badge{ display:inline-block;padding:6px 10px;border-radius:999px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.06); font-weight:900; }

  .row-solved{ border-left:6px solid rgba(0,180,90,.55); background:rgba(0,180,90,.06); }
  .row-unsolved{ border-left:6px solid rgba(255,255,255,.06); }

  .cf-extra{ display:flex;gap:14px;align-items:center;justify-content:flex-start;flex-wrap:wrap; }
  .cf-extra .rating{ font-weight:900; font-size:18px; padding:6px 10px; border-radius:12px; border:1px solid rgba(255,80,80,.35); background:rgba(255,80,80,.10); min-width:64px; text-align:center; }
  .cf-extra .solves{ display:inline-flex;gap:8px;align-items:center;font-weight:900;opacity:.92; padding:6px 10px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.06); }
  .usericon{opacity:.85}
  a.list-row{color:inherit;text-decoration:none;display:grid;}
  a.list-row:hover{border-color:rgba(120,160,255,.25);background:rgba(120,160,255,.06);}
  .list-row:active{transform:translateY(1px);}

  /* ===== MENU BAR (Solved/Unsolved/Hide/OR) — same position ===== */
  .cf-menubar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    padding:10px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.10);
    background:rgba(10,15,25,.20);
  }
  .cf-menubar .mbitem{
    display:flex;
    gap:8px;
    align-items:center;
    padding:8px 10px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.12);
    background:rgba(255,255,255,.06);
    font-weight:900;
    user-select:none;
  }
  .cf-menubar input[type="checkbox"]{
    width:16px;
    height:16px;
    accent-color:#5aa2ff;
  }

  /* ===== Tags dropdown (Select tags -> panel) ===== */
  .tagbox{ border:1px solid rgba(255,255,255,.10); background:rgba(10,15,25,.20); border-radius:14px; padding:12px; }
  .tagpicker-wrap{ position:relative; }
  .tagbtn{
    width:100%;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:10px 12px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.12);
    background:rgba(255,255,255,.06);
    cursor:pointer;
    font-weight:900;
    color:inherit;
  }
  .tagbtn .caret{ opacity:.85; }

.tagpanel{
  position:fixed;          /* ✅ was absolute */
  left:0;
  top:0;
  width:320px;
  z-index:9999999;         /* ✅ stronger */
  border-radius:14px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(12,18,30,.98);
  box-shadow:0 18px 45px rgba(0,0,0,.45);
  overflow:hidden;
  display:none;
}

  .tagpanel .top{
    display:flex;
    gap:10px;
    padding:10px;
    border-bottom:1px solid rgba(255,255,255,.10);
  }
  .tagpanel .clearbtn{
    padding:10px 12px;
    border-radius:10px;
    border:1px solid rgba(255,255,255,.12);
    background:rgba(255,255,255,.06);
    cursor:pointer;
    color:inherit;
    font-weight:900;
  }
  .taglist{
    max-height:250px;
    overflow:auto;
    padding:10px;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
  }
  .tagitem{
  display:inline-flex;
  align-items:center;
  padding:8px 14px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.06);
  cursor:pointer;
  user-select:none;
  font-weight:900;
}

/* hide checkbox but keep it for form submit */
.tagitem input{
  display:none;
}

/* hover */
.tagitem:hover{
  background:rgba(255,255,255,.10);
}

/* selected (ACTIVE) */
.tagitem.on{
  border-color:rgba(80,140,255,.45);
  background:rgba(80,140,255,.18);
}

  .dd-empty{ padding:12px; opacity:.75; font-weight:800; }

  .selected-tags{ margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; min-height:36px; }
  .sel-chip{ display:inline-flex; gap:10px; align-items:center; padding:7px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.06); font-weight:900; }
  .sel-chip .xbtn{ border:0; background:transparent; cursor:pointer; color:inherit; font-weight:900; opacity:.85; padding:0 2px; line-height:1; }
  .sel-chip .xbtn:hover{ opacity:1; }

  /* ✅ prevent dropdown clipping inside cards */
#cfTagsWrap, #cfTagsWrap .tagbox, #cfTagsWrap .tagpicker-wrap{
  overflow: visible !important;
}

</style>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start;">
    <div>
      <h3 style="margin-bottom:6px;">Global Practice Problems</h3>
      <div class="muted">
        Solve UIU problems and Codeforces problems together.
        <?php if ($hasEnroll): ?> Use <b>Course Practice</b> for course-selected problems. <?php endif; ?>
      </div>
    </div>
    <div class="card" style="padding:12px 14px;">
      <div class="muted">Total</div>
      <div style="font-weight:900; font-size:18px;"><?= (int)$total ?></div>
    </div>
  </div>

  <div style="height:12px;"></div>

  <form method="GET" class="filters" id="filterForm">
    <div class="fcol-4">
      <label class="label">Search</label>
      <input name="q" value="<?= e($q) ?>" placeholder="problem title / course / contest id">
    </div>

    <div class="fcol-3">
      <label class="label">Source</label>
      <select name="type" id="typeSel">
        <option value="uiu" <?= $type==="uiu"?"selected":"" ?>>UIU</option>
        <option value="cf"  <?= $type==="cf"?"selected":"" ?>>Codeforces</option>
      </select>
    </div>

    <!-- CF handle fetch (shown only for Codeforces) -->
    <div class="fcol-12" id="cfHandleWrap" style="display:none;">
      <label class="label">Enter Codeforces Handle</label>
      <div class="cf-handle-bar">
        <input name="cf_handle" id="cfHandleInput" value="<?= e($cf_handle) ?>" placeholder="Enter handle (e.g. tourist)">
        <button type="button" class="btn-primary" id="cfFetchBtn">Fetch</button>
        <button type="button" class="badge" id="cfClearBtn">Clear</button>
      </div>
      <div class="muted smallmono" id="cfHandleMsg" style="margin-top:6px;"></div>
    </div>

    <!-- UIU filters -->
    <div class="fcol-3" id="uiuDiffWrap">
      <label class="label">Difficulty (UIU)</label>
      <select name="diff">
        <option value="">All</option>
        <option value="Easy" <?= $diff==="Easy"?"selected":"" ?>>Easy</option>
        <option value="Medium" <?= $diff==="Medium"?"selected":"" ?>>Medium</option>
        <option value="Hard" <?= $diff==="Hard"?"selected":"" ?>>Hard</option>
      </select>
    </div>

    <div class="fcol-2" id="uiuShowWrap">
      <label class="label">Show</label>
      <select name="show">
        <option value="all" <?= $show==="all"?"selected":"" ?>>All</option>
        <option value="solved" <?= $show==="solved"?"selected":"" ?>>Solved</option>
        <option value="unsolved" <?= $show==="unsolved"?"selected":"" ?>>Unsolved</option>
      </select>
    </div>

    <!-- CF sort -->
    <div class="fcol-3" id="cfSortWrap" style="display:none;">
      <label class="label">Sort</label>
      <select name="sort">
        <option value="" <?= $sort===""?"selected":"" ?>>Newest</option>
        <option value="rating_desc" <?= $sort==="rating_desc"?"selected":"" ?>>Rating (High → Low)</option>
        <option value="rating_asc" <?= $sort==="rating_asc"?"selected":"" ?>>Rating (Low → High)</option>
        <option value="solved_desc" <?= $sort==="solved_desc"?"selected":"" ?>>Most Solved</option>
      </select>
    </div>

    <!-- CF rating range -->
    <div class="fcol-3" id="cfRangeWrap" style="display:none;">
      <label class="label">Difficulty Rating (CF)</label>
      <div style="display:flex; gap:8px;">
        <input name="cf_min" type="number" min="0" placeholder="min" value="<?= (int)$cf_min ?>" style="width:50%;">
        <input name="cf_max" type="number" min="0" placeholder="max" value="<?= (int)$cf_max ?>" style="width:50%;">
      </div>
    </div>

    <div class="fcol-3" id="cfContestWrap" style="display:none;">
      <label class="label">Contest ID</label>
      <input name="cf_contest_id" value="<?= e($cf_contest_id) ?>" placeholder="e.g. 2191">
    </div>

    <div class="fcol-3" id="cfIndexWrap" style="display:none;">
      <label class="label">Index</label>
      <input name="cf_index" value="<?= e($cf_index) ?>" placeholder="e.g. A, B2, F1">
    </div>

    <div class="fcol-2">
      <label class="label">Per page</label>
      <select name="per">
        <?php foreach ([10,15,20,30,50] as $n): ?>
          <option value="<?= $n ?>" <?= $per===$n?"selected":"" ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- ✅ CF menu bar (Solved/Unsolved/Hide/OR) in SAME POSITION -->
    <div class="fcol-12" id="cfMenuWrap" data-handleok="<?= $cf_handle_ok ? "1" : "0" ?>" style="display:none;">
      <div class="cf-menubar">
        <!-- hidden real param -->
        <input type="hidden" name="cf_show" id="cfShowHidden" value="<?= e($cf_show) ?>">

        <label class="mbitem">
          <input type="checkbox" id="cfSolvedOnly" <?= ($cf_show==="solved") ? "checked" : "" ?>>
          <span>Show Solved Only</span>
        </label>

        <label class="mbitem">
          <input type="checkbox" id="cfUnsolvedOnly" <?= ($cf_show==="unsolved") ? "checked" : "" ?>>
          <span>Show Unsolved Only</span>
        </label>

        <label class="mbitem">
          <input type="checkbox" name="cf_hide_tags" value="1" <?= ((int)$cf_hide_tags===1)?"checked":"" ?>>
          <span>Hide Tags</span>
        </label>

        <input type="hidden" name="cf_or" value="0">
        <label class="mbitem">
          <input type="checkbox" name="cf_or" value="1" <?= ((int)$cf_or===1)?"checked":"" ?>>
          <span>Combine tags by OR</span>
        </label>

        <span class="muted smallmono">Uncheck OR for AND</span>
      </div>

      <?php if (!$cf_handle_ok): ?>
        <div class="muted smallmono" style="margin-top:8px;">
          Fetch a handle to enable solved/unsolved filter.
        </div>
      <?php endif; ?>
    </div>

    <!-- CF Tags -->
    <div class="fcol-12" id="cfTagsWrap" style="display:none;">
      <div class="tagbox">
        <label class="label">Tags (Codeforces)</label>

        <?php if (!$cf_tags_enabled): ?>
          <div class="muted" style="margin-top:10px;">
            Tags filtering not available (schema mismatch). Page still works without tags.
          </div>
        <?php else: ?>
          <div class="tagpicker-wrap" id="tagPicker">
            <button type="button" class="tagbtn" id="tagBtn" aria-haspopup="listbox" aria-expanded="false">
              <span id="tagBtnText">
                <?= (count($selected_cf_tag_names) > 0) ? ("Tags (".count($selected_cf_tag_names)." selected)") : "Select tags" ?>
              </span>
              <span class="caret">▾</span>
            </button>

            <div class="tagpanel" id="tagPanel">
              <div class="top">
                <input id="tagSearch" name="cf_tag_q" value="<?= e($cf_tag_q) ?>" placeholder="Search tags..." autocomplete="off">
                <button type="button" class="clearbtn" id="tagClear">Clear</button>
              </div>

              <div class="muted smallmono" style="padding:0 10px 8px 10px;">
                Select multiple tags. Close by clicking outside.
              </div>

              <div class="taglist" id="tagList" role="listbox" aria-multiselectable="true">
                <?php foreach ($all_cf_tags as $t): ?>
                  <?php
                    if ($can_filter_tags_by_id) {
                      $val  = (int)($t["id"] ?? 0);
                      $name = (string)($t["tagname"] ?? "");
                      $isOn = in_array($val, $cf_tags, true);
                    } else {
                      $val  = (string)($t["tagname"] ?? "");
                      $name = $val;
                      $isOn = in_array($val, $cf_tags, true);
                    }
                  ?>
                  <label class="tagitem <?= $isOn ? 'on' : '' ?>" data-name="<?= e(strtolower($name)) ?>">
                    <input type="checkbox" name="cf_tags[]" value="<?= e((string)$val) ?>" <?= $isOn?"checked":"" ?>>
                    <span><?= e($name) ?></span>
                  </label>
                <?php endforeach; ?>

                <div class="dd-empty" id="tagEmpty" style="display:none;">No matching tags</div>
              </div>
            </div>

            <div class="selected-tags" id="selectedTags">
              <?php if (empty($selected_cf_tag_names)): ?>
                <span class="muted">No tags selected.</span>
              <?php else: ?>
                <?php foreach ($selected_cf_tag_names as $nm): ?>
                  <span class="sel-chip" data-val="<?= e($nm) ?>">
                    <?= e($nm) ?>
                    <button type="button" class="xbtn" aria-label="Remove">×</button>
                  </span>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="fcol-12" style="display:flex; gap:10px; flex-wrap:wrap;">
      <input type="hidden" name="page" value="1">
      <button class="btn-primary" type="submit">Apply</button>
      <a class="badge" href="/uiu_brainnext/student/problems.php?<?= http_build_query(["type"=>$type]) ?>">Reset</a>
      <?php if ($hasEnroll): ?>
        <a class="badge" href="/uiu_brainnext/student/course_practice.php">Course Practice →</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div style="height:14px;"></div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="muted">No problems found.</div>
  <?php else: ?>
    <div class="list-head">
      <div>Problem</div>
      <div class="hide-sm">Source</div>
      <div class="hide-sm">Difficulty / Rating</div>
    </div>
    <div style="height:10px;"></div>

    <?php foreach ($rows as $r): ?>
      <?php if (($r["_src"] ?? "") === "cf"): ?>
        <?php
          $contest_id = (int)($r["contest_id"] ?? 0);
          $pidx = (string)($r["problem_index"] ?? "");
          $rating = $r["rating"];
          $rating_disp = ($rating === null || $rating === "") ? "-" : (string)(int)$rating;
          $solves = (int)($r["solved_count"] ?? 0);
          $cf_url = "https://codeforces.com/contest/" . $contest_id . "/problem/" . rawurlencode($pidx);
          $pid = (int)($r["id"] ?? 0);
          $isSolved = (int)($r["_is_solved"] ?? 0) === 1;
          $rowClass = $isSolved ? "row-solved" : "row-unsolved";
          $tags = $cf_tags_by_pid[$pid] ?? [];
        ?>
        <a class="list-row row-link <?= e($rowClass) ?>" href="<?= e($cf_url) ?>" target="_blank" rel="noopener">
          <div>
            <div class="p-title"><?= e((string)$r["title"]) ?></div>
            <div class="muted p-sub">Codeforces • <?= e((string)$contest_id . (string)$pidx) ?></div>

            <?php if ((int)$cf_hide_tags !== 1 && !empty($tags)): ?>
              <div class="p-sub" style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php foreach ($tags as $tg): ?>
                  <span class="pill"><?= e($tg) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="hide-sm">
            <span class="cf-badge">Codeforces</span>
          </div>

          <div class="hide-sm">
            <div class="cf-extra">
              <span class="rating"><?= e($rating_disp) ?></span>
              <?php if ($cf_handle_ok): ?>
                <?php if ($isSolved): ?>
                  <span class="pill pill-ac">Solved</span>
                <?php else: ?>
                  <span class="pill">Unsolved</span>
                <?php endif; ?>
              <?php endif; ?>
              <span class="solves"><span class="usericon">👤</span> x <?= (int)$solves ?></span>
            </div>
          </div>
        </a>

      <?php else: /* UIU */ ?>
        <?php
          $pid = (int)$r["id"];
          $solved = (int)($stats[$pid]["solved"] ?? 0);
          $last_v = strtoupper((string)($stats[$pid]["last_verdict"] ?? ""));
          $last_lang = $has_lang ? fmt_lang($stats[$pid]["last_lang"] ?? "") : "";
          $rowClass = $solved ? "row-solved" : "row-unsolved";
        ?>
        <a class="list-row row-link <?= e($rowClass) ?>" href="/uiu_brainnext/student/problem_view.php?id=<?= (int)$pid ?>">
          <div>
            <div class="p-title"><?= e((string)$r["title"]) ?></div>
            <div class="muted p-sub">
              <?= e((string)($r["difficulty"] ?? "")) ?>
              <?php if (!empty($r["short_title"])): ?> • <?= e((string)$r["short_title"]) ?><?php endif; ?>
              • <?= e((string)($r["course_code"] ?? "")) ?>
            </div>
            <?php if (!empty($stats[$pid]["last_time"])): ?>
              <div class="muted smallmono p-sub">Last: <?= e((string)$stats[$pid]["last_time"]) ?></div>
            <?php endif; ?>
          </div>

          <div class="hide-sm">
            <span class="uiu-badge">UIU</span>
          </div>

          <div class="hide-sm">
            <div class="kv">
              <?php if ($solved): ?>
                <span class="pill pill-ac">Solved</span>
              <?php else: ?>
                <span class="pill">Unsolved</span>
              <?php endif; ?>

              <?php if ($has_verdict && $last_v !== ""): ?>
                <span class="<?= e(pill_class($last_v)) ?>"><?= e($last_v) ?></span>
              <?php endif; ?>

              <?php if ($has_lang && $last_lang !== ""): ?>
                <span class="pill"><?= e($last_lang) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php
      $total_pages = (int)ceil(max(1, (int)$total) / $per);
      $qs = $_GET;
    ?>

    <div style="height:12px;"></div>
    <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; align-items:center;">
      <?php if ($page > 1): ?>
        <?php $qs["page"] = $page - 1; ?>
        <a class="badge" href="/uiu_brainnext/student/problems.php?<?= http_build_query($qs) ?>">← Prev</a>
      <?php endif; ?>

      <span class="muted" style="padding:7px 10px;">Page <?= (int)$page ?> / <?= (int)$total_pages ?></span>

      <?php if ($page < $total_pages): ?>
        <?php $qs["page"] = $page + 1; ?>
        <a class="badge" href="/uiu_brainnext/student/problems.php?<?= http_build_query($qs) ?>">Next →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
/* Toggle UIU vs CF blocks */
(function(){
  const typeSel = document.getElementById("typeSel");

  function toggle(){
    const t = (typeSel?.value || "uiu").toLowerCase();
    const isCF = (t === "cf");

    const uiuDiff = document.getElementById("uiuDiffWrap");
    const uiuShow = document.getElementById("uiuShowWrap");

    const cfSort = document.getElementById("cfSortWrap");
    const cfRange = document.getElementById("cfRangeWrap");
    const cfContest = document.getElementById("cfContestWrap");
    const cfIndex = document.getElementById("cfIndexWrap");
    const cfTags = document.getElementById("cfTagsWrap");
    const cfHandle = document.getElementById("cfHandleWrap");
    const cfMenu = document.getElementById("cfMenuWrap");

    if (uiuDiff) uiuDiff.style.display = isCF ? "none" : "";
    if (uiuShow) uiuShow.style.display = isCF ? "none" : "";

    if (cfSort) cfSort.style.display = isCF ? "" : "none";
    if (cfRange) cfRange.style.display = isCF ? "" : "none";
    if (cfContest) cfContest.style.display = isCF ? "" : "none";
    if (cfIndex) cfIndex.style.display = isCF ? "" : "none";
    if (cfTags) cfTags.style.display = isCF ? "" : "none";
    if (cfHandle) cfHandle.style.display = isCF ? "" : "none";
    if (cfMenu) cfMenu.style.display = isCF ? "" : "none";
  }

  if (typeSel) typeSel.addEventListener("change", function(){
    const pageIn = document.querySelector("#filterForm input[name='page']");
    if (pageIn) pageIn.value = "1";
    toggle();
  });

  toggle();
})();
</script>

<script>
// CF handle sync (Fetch/Clear)
(function(){
  const input = document.getElementById("cfHandleInput");
  const fetchBtn = document.getElementById("cfFetchBtn");
  const clearBtn = document.getElementById("cfClearBtn");
  const msg = document.getElementById("cfHandleMsg");
  if (!input || !fetchBtn || !clearBtn) return;

  function setMsg(t){ if (msg) msg.textContent = t || ""; }

  async function post(action){
    const handle = (input.value || "").trim();
    if (action === "sync" && !handle) { setMsg("Please enter a Codeforces handle."); return; }

    setMsg(action === "sync" ? "Fetching solved problems..." : "Clearing handle data...");

    try{
      const body = new URLSearchParams();
      body.set("action", action);
      body.set("handle", handle);

      const r = await fetch("/uiu_brainnext/api/cf_user_sync.php", {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body
      });

      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j || !j.ok) {
        setMsg((j && j.message) ? j.message : "Request failed.");
        return;
      }

      const url = new URL(window.location.href);
      url.searchParams.set("type", "cf");
      url.searchParams.set("page", "1");

      if (action === "sync") {
        url.searchParams.set("cf_handle", handle);
        url.searchParams.set("cf_verified", "1");
      } else {
        url.searchParams.delete("cf_handle");
        url.searchParams.delete("cf_verified");
      }
      window.location.href = url.toString();
    } catch(e){
      setMsg("Request failed.");
    }
  }

  fetchBtn.addEventListener("click", () => post("sync"));
  clearBtn.addEventListener("click", () => post("clear"));
})();
</script>

<script>
/* Menu bar solved/unsolved -> hidden cf_show (mutually exclusive; only after handle verified) */
(function(){
  const wrap = document.getElementById("cfMenuWrap");
  const handleOk = (wrap?.dataset?.handleok === "1");
  const solved = document.getElementById("cfSolvedOnly");
  const unsolved = document.getElementById("cfUnsolvedOnly");
  const hidden = document.getElementById("cfShowHidden");
  if (!solved || !unsolved || !hidden) return;

  function sync(){
    if (!handleOk) {
      solved.checked = false;
      unsolved.checked = false;
      hidden.value = "all";
      return;
    }
    if (solved.checked) { unsolved.checked = false; hidden.value = "solved"; return; }
    if (unsolved.checked) { solved.checked = false; hidden.value = "unsolved"; return; }
    hidden.value = "all";
  }

  solved.addEventListener("change", sync);
  unsolved.addEventListener("change", sync);
  sync();
})();
</script>

<script>
/* Tags dropdown behavior (Select tags -> dropdown panel) — FIXED */
(function(){
  const picker = document.getElementById("tagPicker");
  const btn = document.getElementById("tagBtn");
  const btnText = document.getElementById("tagBtnText");
  const panel = document.getElementById("tagPanel");
  const search = document.getElementById("tagSearch");
  const clearBtn = document.getElementById("tagClear");
  const list = document.getElementById("tagList");
  const selectedBox = document.getElementById("selectedTags");
  const empty = document.getElementById("tagEmpty");

  if (!picker || !btn || !btnText || !panel || !search || !list || !selectedBox) return;

  // ✅ Put panel on top of everything
  panel.style.position = "fixed";
  panel.style.zIndex = "99999999";
  panel.style.display = "none";

  let isOpen = false;

  function attachPanelInputsToForm(){
  // panel moved to <body> => inputs won't submit unless tied to the form
  panel.querySelectorAll("input, select, textarea, button").forEach(el => {
    el.setAttribute("form", "filterForm");
  });
}

let _autoSubmitTimer = null;
function autoSubmit(){
  const form = document.getElementById("filterForm");
  if (!form) return;

  const pageIn = form.querySelector('input[name="page"]');
  if (pageIn) pageIn.value = "1";

  if (_autoSubmitTimer) clearTimeout(_autoSubmitTimer);
  _autoSubmitTimer = setTimeout(() => form.submit(), 120);
}


  function cssEscape(val){
    val = String(val || "");
    if (window.CSS && CSS.escape) return CSS.escape(val);
    return val.replace(/["\\]/g, "\\$&");
  }

  function positionPanel(){
  const r = btn.getBoundingClientRect();

  // width aligned with button
  const w = r.width;
  panel.style.width = w + "px";

  // keep inside viewport horizontally
  const left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));
  panel.style.left = left + "px";

  // ===== Decide open direction (down if space, else up) =====
  const gap = 8;

  // Find Apply button (or fallback to form bottom)
  const applyBtn = document.querySelector('#filterForm button[type="submit"]');
  let applyTop = window.innerHeight; // fallback (no Apply found)
  if (applyBtn) {
    const ar = applyBtn.getBoundingClientRect();
    applyTop = ar.top; // top of Apply button
  }

  // available space down: from button bottom to Apply top (so it stays "under apply" area)
  const spaceDown = Math.max(0, (applyTop - gap) - (r.bottom + gap));

  // available space up: from top padding to button top
  const spaceUp = Math.max(0, (r.top - gap) - 8);

  // set max height so it never overlaps Apply
  const desiredMax = 340; // you can tweak if you want
  const maxH = Math.max(180, Math.min(desiredMax, Math.max(spaceDown, spaceUp)));

  // apply max height to list area
  const tagList = panel.querySelector(".taglist");
  if (tagList) tagList.style.maxHeight = Math.max(140, maxH - 110) + "px"; // keep header/search visible

  // decide direction
  const openDown = (spaceDown >= 200) || (spaceDown >= spaceUp);

  // show first so offsetHeight is correct (if needed)
  // (panel is already display:block when this runs)
  requestAnimationFrame(() => {
    const ph = panel.offsetHeight || 320;

    if (openDown) {
      panel.style.top = (r.bottom + gap) + "px";
    } else {
      panel.style.top = Math.max(8, r.top - gap - ph) + "px";
    }
  });
}

 function openPanel(){
  if (panel.parentElement !== document.body) {
    document.body.appendChild(panel);
  }

  attachPanelInputsToForm(); // ✅ ADD THIS LINE

  panel.style.display = "block";
  btn.setAttribute("aria-expanded", "true");
  isOpen = true;

  requestAnimationFrame(() => {
    positionPanel();
    try { search.focus(); } catch(e){}
  });
}


  function closePanel(){
    panel.style.display = "none";
    btn.setAttribute("aria-expanded", "false");
    isOpen = false;
  }

  function renderSelected(){
    const checked = list.querySelectorAll('input[name="cf_tags[]"]:checked');
    if (checked.length === 0){
      selectedBox.innerHTML = '<span class="muted">No tags selected.</span>';
      btnText.textContent = "Select tags";
      return;
    }
    let html = "";
    checked.forEach(cb=>{
      const label = cb.closest(".tagitem");
      const txt = label ? (label.querySelector("span")?.textContent || cb.value) : cb.value;
      html += `<span class="sel-chip" data-val="${cb.value}">
        ${txt} <button type="button" class="xbtn" aria-label="Remove">×</button>
      </span>`;
    });
    selectedBox.innerHTML = html;
    btnText.textContent = `Tags (${checked.length} selected)`;
  }

  // ✅ OPEN/CLOSE button (use pointerdown so it beats document handler)
  btn.addEventListener("pointerdown", function(e){
    e.preventDefault();
    e.stopPropagation();
    if (isOpen) closePanel();
    else openPanel();
  });

  // ✅ Stop clicks inside panel from closing it
  panel.addEventListener("pointerdown", function(e){
    e.stopPropagation();
  });

  // ✅ Close ONLY when clicking outside BOTH btn and panel
  document.addEventListener("pointerdown", function(e){
    if (!isOpen) return;
    if (btn.contains(e.target)) return;
    if (panel.contains(e.target)) return;
    closePanel();
  });

  document.addEventListener("keydown", function(e){
    if (e.key === "Escape") closePanel();
  });

  window.addEventListener("scroll", function(){
    if (isOpen) positionPanel();
  }, true);

  window.addEventListener("resize", function(){
    if (isOpen) positionPanel();
  });

  // Tag search filter
  search.addEventListener("input", function(){
    const q = (search.value || "").trim().toLowerCase();
    let shown = 0;

    list.querySelectorAll(".tagitem").forEach(item=>{
      const nm = item.getAttribute("data-name") || "";
      const ok = (q === "" || nm.includes(q));
      item.style.display = ok ? "" : "none";
      if (ok) shown++;
    });

    if (empty) empty.style.display = shown === 0 ? "" : "none";
    if (!isOpen) openPanel();
    else positionPanel();
  });

  if (clearBtn){
    clearBtn.addEventListener("click", function(e){
      e.preventDefault();
      list.querySelectorAll('input[name="cf_tags[]"]:checked').forEach(cb => cb.checked = false);
      list.querySelectorAll(".tagitem").forEach(item => item.classList.remove("on"));
      search.value = "";
      list.querySelectorAll(".tagitem").forEach(item => item.style.display = "");
      if (empty) empty.style.display = "none";
      renderSelected();
       autoSubmit(); 
    });
  }

  // Click a tag chip in dropdown
  list.addEventListener("click", function(e){
    const item = e.target.closest(".tagitem");
    if (!item) return;

    const cb = item.querySelector('input[type="checkbox"]');
    if (!cb) return;

    cb.checked = !cb.checked;
    item.classList.toggle("on", cb.checked);
    renderSelected();
     autoSubmit();
  });

  // Remove from selected chips
  selectedBox.addEventListener("click", function(e){
    const x = e.target.closest(".xbtn");
    if (!x) return;

    const chip = x.closest(".sel-chip");
    if (!chip) return;

    const val = chip.getAttribute("data-val");
    const cb = list.querySelector(`input[name="cf_tags[]"][value="${cssEscape(val)}"]`);
    if (cb) {
      cb.checked = false;
      const item = cb.closest(".tagitem");
      if (item) item.classList.remove("on");
    }
    renderSelected();
     autoSubmit(); 
  });
  
  attachPanelInputsToForm(); 
  renderSelected();
})();
</script>


<?php ui_end(); ?>























