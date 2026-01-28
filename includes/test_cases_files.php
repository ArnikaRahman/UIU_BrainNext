<?php
// Option B: file-based hidden testcases for TEACHER-created TESTS.
//
// Directory layout supported inside judge/testcases/test_{TEST_ID}/ :
//  1) inputs/1.txt + outputs/1.txt   (same basename)
//  2) in/1.txt     + out/1.txt       (same basename)
//  3) root: 1.in   + 1.out

function testcases_dir_for_test(int $test_id): string {
  $base = dirname(__DIR__) . "/judge/testcases";
  return rtrim($base, "/\\") . "/test_" . (int)$test_id;
}

/**
 * Returns list of cases:
 *  [ [ 'name' => '1', 'in' => '...', 'out' => '...' ], ... ]
 */
function load_test_cases_from_dir(string $dir): array {
  $cases = [];
  $dir = rtrim($dir, "/\\");
  if ($dir === "" || !is_dir($dir)) return [];

  // layout 1/2
  $pairs = [
    ["inputs", "outputs"],
    ["in", "out"],
  ];
  foreach ($pairs as [$inD, $outD]) {
    $inPath  = $dir . DIRECTORY_SEPARATOR . $inD;
    $outPath = $dir . DIRECTORY_SEPARATOR . $outD;
    if (is_dir($inPath) && is_dir($outPath)) {
      $inFiles = glob($inPath . DIRECTORY_SEPARATOR . "*.txt");
      if (!$inFiles) $inFiles = glob($inPath . DIRECTORY_SEPARATOR . "*");
      foreach ($inFiles as $inFile) {
        if (!is_file($inFile)) continue;
        $base = pathinfo($inFile, PATHINFO_FILENAME);
        $outFileTxt = $outPath . DIRECTORY_SEPARATOR . $base . ".txt";
        $outFileAny = glob($outPath . DIRECTORY_SEPARATOR . $base . ".*");
        $outFile = is_file($outFileTxt) ? $outFileTxt : (($outFileAny && is_file($outFileAny[0])) ? $outFileAny[0] : "");
        if ($outFile === "") continue;

        $cases[] = [
          "name" => $base,
          "in"   => (string)@file_get_contents($inFile),
          "out"  => (string)@file_get_contents($outFile),
        ];
      }
      if (!empty($cases)) return normalize_case_order($cases);
    }
  }

  // layout 3: root 1.in + 1.out
  $inFiles = glob($dir . DIRECTORY_SEPARATOR . "*.in");
  if ($inFiles) {
    foreach ($inFiles as $inFile) {
      if (!is_file($inFile)) continue;
      $base = pathinfo($inFile, PATHINFO_FILENAME);
      $outFile = $dir . DIRECTORY_SEPARATOR . $base . ".out";
      if (!is_file($outFile)) continue;
      $cases[] = [
        "name" => $base,
        "in"   => (string)@file_get_contents($inFile),
        "out"  => (string)@file_get_contents($outFile),
      ];
    }
  }

  return normalize_case_order($cases);
}

function normalize_case_order(array $cases): array {
  usort($cases, function($a, $b){
    $an = (string)($a["name"] ?? "");
    $bn = (string)($b["name"] ?? "");
    // numeric names first
    if (ctype_digit($an) && ctype_digit($bn)) return (int)$an <=> (int)$bn;
    return strcmp($an, $bn);
  });
  return $cases;
}

/**
 * Distribute total marks across cases (equal weight).
 * Returns cases with `points`.
 */
function attach_points_to_cases(array $cases, int $total_marks): array {
  $n = count($cases);
  if ($n <= 0) return [];
  $total_marks = max(1, (int)$total_marks);

  $base = intdiv($total_marks, $n);
  $rem  = $total_marks - ($base * $n);
  foreach ($cases as $i => $c) {
    $cases[$i]["points"] = $base + ($i < $rem ? 1 : 0);
  }
  return $cases;
}
