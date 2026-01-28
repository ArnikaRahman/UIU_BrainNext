<?php
require_once __DIR__ . "/../includes/auth_teacher.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";

$teacher_id = (int)($_SESSION["user"]["id"] ?? 0);

/* ---------- DB helpers (MariaDB safe) ---------- */
function db_has_col(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param("ss", $table, $col);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}
function db_table_exists(mysqli $conn, string $table): bool {
  $sql = "SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param("s", $table);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}

$missing = [];
foreach (["courses","sections","problems","problem_samples"] as $t) {
  if (!db_table_exists($conn, $t)) $missing[] = $t;
}

$has_short_title = db_has_col($conn, "problems", "short_title");
$has_created_by  = db_has_col($conn, "problems", "created_by");
$has_sample_in   = db_has_col($conn, "problems", "sample_input");
$has_sample_out  = db_has_col($conn, "problems", "sample_output");

/* teacher courses (from sections teacher teaches) */
$courses = [];
$st = $conn->prepare("
  SELECT DISTINCT c.id, c.code, c.title
  FROM sections s
  JOIN courses c ON c.id = s.course_id
  WHERE s.teacher_id = ?
  ORDER BY c.code ASC
");
$st->bind_param("i", $teacher_id);
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $courses[] = $row;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $course_id  = (int)($_POST["course_id"] ?? 0);
  $shortTitle = trim($_POST["short_title"] ?? "");
  $statement  = trim($_POST["statement"] ?? "");
  $difficulty = trim($_POST["difficulty"] ?? "Easy");
  $points     = (int)($_POST["points"] ?? 10);

  $sample_in  = $_POST["sample_input"] ?? [];
  $sample_out = $_POST["sample_output"] ?? [];

  // keep only non-empty rows
  $samples = [];
  for ($i=0; $i<count($sample_in); $i++) {
    $in = trim((string)$sample_in[$i]);
    $out = trim((string)$sample_out[$i]);
    if ($in === "" && $out === "") continue;
    $samples[] = ["in"=>$in, "out"=>$out];
  }

  if ($course_id <= 0 || $statement === "") {
    set_flash("err", "Course and statement are required.");
    redirect("/uiu_brainnext/teacher/teacher_add_problem.php");
  }
  if ($has_short_title && $shortTitle === "") {
    set_flash("err", "Short title is required.");
    redirect("/uiu_brainnext/teacher/teacher_add_problem.php");
  }
  if (count($samples) === 0) {
    set_flash("err", "Add at least 1 sample input/output row.");
    redirect("/uiu_brainnext/teacher/teacher_add_problem.php");
  }

  // insert problem (store first sample in problems table too, for compatibility)
  $firstIn  = $samples[0]["in"];
  $firstOut = $samples[0]["out"];

  // Build INSERT dynamically based on columns available
  $cols = ["course_id", "title", "statement", "difficulty", "points"];
  $vals = [$course_id, $statement, $statement, $difficulty, $points]; // title not used if short_title exists; safe fallback

  // We will use statement as title fallback if no short_title column.
  // If short_title exists, we set title column = statement (or could be ignored depending your schema).
  // Many schemas have (title, statement). If yours has only statement, you can rename.

  // Detect if problems has a "title" column
  $has_title_col = db_has_col($conn, "problems", "title");
  if (!$has_title_col) {
    // if no title column, remove it
    $cols = ["course_id", "statement", "difficulty", "points"];
    $vals = [$course_id, $statement, $difficulty, $points];
  }

  if ($has_short_title) {
    if ($has_title_col) {
      $cols = ["course_id","title","short_title","statement","difficulty","points"];
      $vals = [$course_id, $shortTitle, $shortTitle, $statement, $difficulty, $points];
    } else {
      $cols = ["course_id","short_title","statement","difficulty","points"];
      $vals = [$course_id, $shortTitle, $statement, $difficulty, $points];
    }
  }

  if ($has_sample_in && $has_sample_out) {
    $cols[] = "sample_input";
    $cols[] = "sample_output";
    $vals[] = $firstIn;
    $vals[] = $firstOut;
  }

  if ($has_created_by) {
    $cols[] = "created_by";
    $vals[] = $teacher_id;
  }

  // prepare insert
  $placeholders = implode(",", array_fill(0, count($cols), "?"));
  $colSql = implode(",", $cols);
  $sql = "INSERT INTO problems ($colSql) VALUES ($placeholders)";
  $ins = $conn->prepare($sql);

  // types
  $types = "";
  foreach ($vals as $v) {
    $types .= is_int($v) ? "i" : "s";
  }
  $ins->bind_param($types, ...$vals);

  if (!$ins->execute()) {
    set_flash("err", "Insert failed: " . $conn->error);
    redirect("/uiu_brainnext/teacher/teacher_add_problem.php");
  }

  $problem_id = (int)$conn->insert_id;

  // insert samples table
  $insS = $conn->prepare("INSERT INTO problem_samples(problem_id, sample_input, sample_output, sort_order) VALUES(?, ?, ?, ?)");
  $order = 1;
  foreach ($samples as $s) {
    $in = $s["in"];
    $out = $s["out"];
    $insS->bind_param("issi", $problem_id, $in, $out, $order);
    $insS->execute();
    $order++;
  }

  set_flash("ok", "Problem added with " . count($samples) . " sample case(s).");
  redirect("/uiu_brainnext/teacher/teacher_add_problem.php");
}

ui_start("Add Problem", "Teacher Panel");
ui_top_actions([
  ["Dashboard", "/teacher/dashboard.php"],
  ["My Sections", "/teacher/teacher_sections.php"],
  ["Create Test", "/teacher/teacher_create_test.php"],
]);

$err = get_flash("err");
$ok  = get_flash("ok");
?>

<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

<?php if (!empty($missing)): ?>
  <div class="card">
    <h3>Setup Missing</h3>
    <div class="muted">Missing tables: <b><?= e(implode(", ", $missing)) ?></b></div>
  </div>
  <?php ui_end(); exit; ?>
<?php endif; ?>

<div class="card">
  <h3>Add Problem</h3>
  <p class="muted">Add problem with multiple sample input/output rows.</p>

  <form method="POST">
    <label class="label">Course</label>
    <select name="course_id" required>
      <option value="">Select course</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c["id"] ?>"><?= e($c["code"]) ?> - <?= e($c["title"]) ?></option>
      <?php endforeach; ?>
    </select>

    <div style="height:12px;"></div>

    <?php if ($has_short_title): ?>
      <label class="label">Heading (1–2 words)</label>
      <input name="short_title" placeholder="Example: Factorial" required>
      <div style="height:12px;"></div>
    <?php endif; ?>

    <label class="label">Statement</label>
    <textarea name="statement" placeholder="Write the full problem statement..." required></textarea>

    <div style="height:12px;"></div>

    <div class="grid">
      <div class="col-6 card">
        <label class="label">Difficulty</label>
        <select name="difficulty">
          <option>Easy</option>
          <option>Medium</option>
          <option>Hard</option>
        </select>
      </div>
      <div class="col-6 card">
        <label class="label">Points</label>
        <input type="number" name="points" value="10" min="0">
      </div>
    </div>

    <div style="height:12px;"></div>

    <div class="card">
      <h3 style="margin-bottom:6px;">Sample Cases</h3>
      <div class="muted">Add multiple sample input/output pairs (table rows).</div>

      <div style="height:10px;"></div>

      <table class="table" id="samplesTable">
        <thead>
          <tr>
            <th style="width:50%;">Sample Input</th>
            <th style="width:50%;">Sample Output</th>
            <th style="width:110px;"></th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><textarea name="sample_input[]" placeholder="e.g. 5" required></textarea></td>
            <td><textarea name="sample_output[]" placeholder="e.g. 120" required></textarea></td>
            <td class="row-actions">
            <button type="button" class="badge" onclick="removeRow(this)">Remove</button>
             </td>
          </tr>
        </tbody>
      </table>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
        <button type="button" class="badge" onclick="addRow()">+ Add Row</button>
      </div>
    </div>

    <div style="height:12px;"></div>
    <button class="btn-primary" type="submit">Save Problem</button>
  </form>
</div>

<script>
function addRow(){
  const tb = document.querySelector("#samplesTable tbody");
  const tr = document.createElement("tr");
  tr.innerHTML = `
    <td><textarea name="sample_input[]" placeholder="Sample input" required></textarea></td>
    <td><textarea name="sample_output[]" placeholder="Sample output" required></textarea></td>
   <td class="row-actions">
  <button type="button" class="badge" onclick="removeRow(this)">Remove</button>
  </td>
  tb.appendChild(tr);
}
function removeRow(btn){
  const tb = document.querySelector("#samplesTable tbody");
  if(tb.children.length <= 1) return; // keep at least 1 row
  btn.closest("tr").remove();
}
</script>

<?php ui_end(); ?>



