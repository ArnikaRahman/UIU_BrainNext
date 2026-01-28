<?php
require_once __DIR__ . "/../includes/auth_student.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/db.php";

$user_id = $_SESSION["user"]["id"];

ui_start("Codeforces Practice", "Global Practice");

$q = trim($_GET["q"] ?? "");
$min = (int)($_GET["min"] ?? 0);
$max = (int)($_GET["max"] ?? 4000);

$where = "WHERE 1=1";
if ($q !== "") {
  $where .= " AND title LIKE '%".$conn->real_escape_string($q)."%'";
}
if ($min > 0) $where .= " AND rating >= $min";
if ($max > 0) $where .= " AND rating <= $max";

$sql = "
  SELECT p.*,
    (SELECT 1 FROM cf_user_solved s WHERE s.problem_id=p.id AND s.user_id=$user_id) AS solved
  FROM cf_problems p
  $where
  ORDER BY rating ASC
  LIMIT 200
";

$res = $conn->query($sql);
?>

<div class="card">
  <h3>Codeforces Problems</h3>
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;">
    <input name="q" placeholder="Search title" value="<?= e($q) ?>">
    <input name="min" placeholder="Min rating" value="<?= e($min) ?>" style="width:120px;">
    <input name="max" placeholder="Max rating" value="<?= e($max) ?>" style="width:120px;">
    <button class="btn-primary">Apply</button>
  </form>
</div>

<div style="height:14px;"></div>

<div class="card">
<table class="table">
<thead>
<tr>
  <th>Status</th>
  <th>Problem</th>
  <th>Rating</th>
  <th>Solved</th>
  <th>Open</th>
</tr>
</thead>
<tbody>
<?php while ($r = $res->fetch_assoc()): ?>
<tr class="<?= $r["solved"] ? "row-solved" : "" ?>">
  <td><?= $r["solved"] ? "✔" : "" ?></td>
  <td><?= e($r["contest_id"].$r["problem_index"]." - ".$r["title"]) ?></td>
  <td><?= e($r["rating"] ?? "-") ?></td>
  <td><?= e($r["solved_count"]) ?></td>
  <td>
    <a class="badge" target="_blank"
       href="https://codeforces.com/problemset/problem/<?= $r["contest_id"] ?>/<?= $r["problem_index"] ?>">
       Open
    </a>
  </td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>

<?php ui_end(); ?>
