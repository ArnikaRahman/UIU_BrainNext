<?php
// includes/judge_cases_files.php
// Load testcases from files:
// judge/testcases/problem_{id}/in1.txt out1.txt ...

declare(strict_types=1);

function load_problem_cases_from_files(int $problem_id): array {
  $base = __DIR__ . "/../judge/testcases/problem_" . $problem_id;
  if (!is_dir($base)) return [];

  $ins = glob($base . "/in*.txt");
  if (!$ins) return [];

  // sort by number (in1, in2, in10)
  usort($ins, function($a, $b){
    $na = (int)preg_replace('/\D+/', '', basename($a));
    $nb = (int)preg_replace('/\D+/', '', basename($b));
    return $na <=> $nb;
  });

  $cases = [];
  foreach ($ins as $infile) {
    $num = (int)preg_replace('/\D+/', '', basename($infile));
    $outfile = $base . "/out{$num}.txt";
    if (!file_exists($outfile)) continue;

    $cases[] = [
      "in"  => (string)file_get_contents($infile),
      "out" => (string)file_get_contents($outfile),
    ];
  }
  return $cases;
}
