<?php
require_once __DIR__ . "/../includes/auth_admin.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------------- helpers ---------------- */
function clamp_int($v, $min, $max) {
  $n = (int)$v;
  if ($n < $min) return $min;
  if ($n > $max) return $max;
  return $n;
}
function is_valid_ident(string $s): bool {
  return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $s);
}
function db_current_name(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db");
  $row = $r ? $r->fetch_assoc() : null;
  return (string)($row["db"] ?? "");
}
function table_exists(mysqli $conn, string $db, string $table): bool {
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
function get_table_columns(mysqli $conn, string $db, string $table): array {
  $cols = [];
  $st = $conn->prepare("
    SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME   = ?
    ORDER BY ORDINAL_POSITION
  ");
  $st->bind_param("ss", $db, $table);
  $st->execute();
  $res = $st->get_result();
  while ($row = $res->fetch_assoc()) {
    $cols[] = [
      "name" => (string)$row["COLUMN_NAME"],
      "data_type" => (string)$row["DATA_TYPE"],
      "column_type" => (string)$row["COLUMN_TYPE"],
    ];
  }
  return $cols;
}
function safe_bt(string $tableOrCol): string {
  // safe backtick for identifiers that already validated by regex
  return "`" . str_replace("`", "``", $tableOrCol) . "`";
}

/* ---------------- input ---------------- */
$ALLOWED_DBS = ["uiu_brainnext", "uiu_brainnext_meta"];

$db    = (string)($_GET["db"] ?? "uiu_brainnext");
$table = (string)($_GET["table"] ?? "");
$page  = clamp_int($_GET["page"] ?? 1, 1, 1000000);
$per   = clamp_int($_GET["per"] ?? 25, 5, 200);

$q     = trim((string)($_GET["q"] ?? ""));
$sort  = trim((string)($_GET["sort"] ?? ""));
$dir   = strtolower(trim((string)($_GET["dir"] ?? "asc")));
$dir   = ($dir === "desc") ? "DESC" : "ASC";

if (!in_array($db, $ALLOWED_DBS, true)) {
  ui_start("Table View", "Admin Panel");
  echo '<div class="alert err">Invalid database.</div>';
  ui_end();
  exit;
}
if ($table === "" || !is_valid_ident($table)) {
  ui_start("Table View", "Admin Panel");
  echo '<div class="alert err">Invalid table name.</div>';
  ui_end();
  exit;
}

/* we query INFORMATION_SCHEMA using same mysqli connection */
$conn->select_db("information_schema");

if (!table_exists($conn, $db, $table)) {
  ui_start("Table View", "Admin Panel");
  echo '<div class="alert err">Table not found.</div>';
  ui_end();
  exit;
}

$cols = get_table_columns($conn, $db, $table);
if (empty($cols)) {
  ui_start("Table View", "Admin Panel");
  echo '<div class="alert err">No columns found for this table.</div>';
  ui_end();
  exit;
}

$colNames = array_map(fn($c) => $c["name"], $cols);

/* default sort = first column */
if ($sort === "" || !in_array($sort, $colNames, true)) {
  $sort = $colNames[0];
}

/* choose searchable columns (text-ish) */
$textTypes = ["char","varchar","text","tinytext","mediumtext","longtext"];
$searchCols = [];
$numericCols = [];
foreach ($cols as $c) {
  $dt = strtolower($c["data_type"]);
  if (in_array($dt, $textTypes, true)) $searchCols[] = $c["name"];
  if (in_array($dt, ["int","tinyint","smallint","mediumint","bigint","decimal","float","double"], true)) $numericCols[] = $c["name"];
}

/* ---------------- build query ---------------- */
$conn->select_db($db);

$whereSql = "";
$types = "";
$params = [];

/* search */
if ($q !== "") {
  $parts = [];

  // text columns: LIKE
  if (!empty($searchCols)) {
    foreach ($searchCols as $c) {
      $parts[] = "CAST(" . safe_bt($c) . " AS CHAR) LIKE ?";
      $types .= "s";
      $params[] = "%" . $q . "%";
    }
  }

  // numeric columns: exact match when numeric
  if (is_numeric($q) && !empty($numericCols)) {
    $num = $q + 0; // numeric
    foreach ($numericCols as $c) {
      $parts[] = safe_bt($c) . " = ?";
      // bind as string is ok in mysqli, but we keep it numeric-ish:
      $types .= "s";
      $params[] = (string)$num;
    }
  }

  if (!empty($parts)) {
    $whereSql = " WHERE (" . implode(" OR ", $parts) . ") ";
  }
}

/* total rows */
$countSql = "SELECT COUNT(*) AS n FROM " . safe_bt($table) . $whereSql;
$stc = $conn->prepare($countSql);
if ($types !== "") {
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => $v) $bind[] = &$params[$k];
  call_user_func_array([$stc, "bind_param"], $bind);
}
$stc->execute();
$total = 0;
$rc = $stc->get_result()->fetch_assoc();
$total = (int)($rc["n"] ?? 0);
$stc->close();

/* pagination */
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per;

/* main rows */
$orderSql = " ORDER BY " . safe_bt($sort) . " " . $dir . " ";
$dataSql  = "SELECT * FROM " . safe_bt($table) . $whereSql . $orderSql . " LIMIT ? OFFSET ?";

$st = $conn->prepare($dataSql);

/* bind: search params + limit/offset */
$types2 = $types . "ii";
$params2 = $params;
$params2[] = $per;
$params2[] = $offset;

$bind2 = [];
$bind2[] = $types2;
foreach ($params2 as $k => $v) $bind2[] = &$params2[$k];
call_user_func_array([$st, "bind_param"], $bind2);

$st->execute();
$res = $st->get_result();

/* ---------------- UI ---------------- */
ui_start("Table View", "Admin Panel");
ui_top_actions([
  ["Dashboard", "/admin/dashboard.php"],
  ["3D Schema", "/admin/schema_3d_demo.php?db=" . urlencode($db)],
]);

/* build URL helper */
function build_url(array $override = []): string {
  $base = [
    "db" => $_GET["db"] ?? "uiu_brainnext",
    "table" => $_GET["table"] ?? "",
    "page" => $_GET["page"] ?? 1,
    "per" => $_GET["per"] ?? 25,
    "q" => $_GET["q"] ?? "",
    "sort" => $_GET["sort"] ?? "",
    "dir" => $_GET["dir"] ?? "asc",
  ];
  foreach ($override as $k => $v) $base[$k] = $v;
  return "/uiu_brainnext/admin/schema_table_view.php?" . http_build_query($base);
}
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-end;">
    <div>
      <h3 style="margin:0 0 6px;"><?= e($db) ?> / <?= e($table) ?></h3>
      <div class="muted">
        Rows: <b><?= (int)$total ?></b> • Page <b><?= (int)$page ?></b> / <b><?= (int)$pages ?></b>
      </div>
    </div>

    <form method="GET" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <input type="hidden" name="db" value="<?= e($db) ?>">
      <input type="hidden" name="table" value="<?= e($table) ?>">

      <div>
        <label class="label">Search</label>
        <input name="q" value="<?= e($q) ?>" placeholder="Search in rows..." style="min-width:240px;">
      </div>

      <div>
        <label class="label">Per page</label>
        <select name="per">
          <?php foreach ([25,50,100,200] as $n): ?>
            <option value="<?= (int)$n ?>" <?= $per===$n?"selected":"" ?>><?= (int)$n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button class="badge" type="submit">Apply</button>
      <a class="badge" href="<?= e(build_url(["q"=>"", "page"=>1])) ?>" style="text-decoration:none;">Clear</a>
    </form>
  </div>

  <div style="height:12px;"></div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <?php foreach ($colNames as $cn): ?>
            <?php
              $isSort = ($cn === $sort);
              $nextDir = ($isSort && $dir === "ASC") ? "desc" : "asc";
              $link = build_url(["sort"=>$cn, "dir"=>$nextDir, "page"=>1]);
            ?>
            <th>
              <a href="<?= e($link) ?>" style="color:inherit; text-decoration:none;">
                <?= e($cn) ?>
                <?php if ($isSort): ?>
                  <span class="muted"><?= $dir === "ASC" ? "▲" : "▼" ?></span>
                <?php endif; ?>
              </a>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php if ($total === 0): ?>
          <tr><td colspan="<?= (int)count($colNames) ?>" class="muted">No rows found.</td></tr>
        <?php else: ?>
          <?php while ($row = $res->fetch_assoc()): ?>
            <tr>
              <?php foreach ($colNames as $cn): ?>
                <?php
                  $v = $row[$cn];
                  if ($v === null) $out = "<span class='muted'>NULL</span>";
                  else {
                    $s = (string)$v;
                    if (strlen($s) > 180) $s = substr($s, 0, 180) . "…";
                    $out = e($s);
                  }
                ?>
                <td><?= $out ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endwhile; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="height:12px;"></div>

  <!-- pagination -->
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
    <div class="muted">
      Showing <?= (int)min($total, $offset + 1) ?>–<?= (int)min($total, $offset + $per) ?> of <?= (int)$total ?>
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="badge" style="text-decoration:none; <?= $page<=1?'opacity:.5; pointer-events:none;':'' ?>"
         href="<?= e(build_url(["page"=>1])) ?>">First</a>

      <a class="badge" style="text-decoration:none; <?= $page<=1?'opacity:.5; pointer-events:none;':'' ?>"
         href="<?= e(build_url(["page"=>max(1,$page-1)])) ?>">Prev</a>

      <span class="badge" style="opacity:.85;">Page <?= (int)$page ?> / <?= (int)$pages ?></span>

      <a class="badge" style="text-decoration:none; <?= $page>=$pages?'opacity:.5; pointer-events:none;':'' ?>"
         href="<?= e(build_url(["page"=>min($pages,$page+1)])) ?>">Next</a>

      <a class="badge" style="text-decoration:none; <?= $page>=$pages?'opacity:.5; pointer-events:none;':'' ?>"
         href="<?= e(build_url(["page"=>$pages])) ?>">Last</a>
    </div>
  </div>
</div>

<?php
$st->close();
ui_end();
