<?php
// includes/judge_local.php
// Local judge (Windows/XAMPP) - C/C++ only
// Safe against infinite input wait and infinite loops (timeout)

declare(strict_types=1);
file_put_contents(__DIR__ . "/../sandbox/judge_loaded.txt", "LOADED: " . __FILE__ . "\n", FILE_APPEND);
function judge_cfg(): array {
  return [
    "gpp" => "g++",
    "gcc" => "gcc",

    "sandbox_dir" => __DIR__ . "/../sandbox",
    "testcase_base_dir" => __DIR__ . "/../judge/testcases",

    "time_limit_ms" => 2000,
    "compile_timeout_ms" => 8000,

    // ✅ global whole-judge limit (IMPORTANT)
    "total_limit_ms" => 9000,

    "output_limit_kb" => 256,
  ];
}

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
}

function win_kill_tree(int $pid): void {
  if ($pid <= 0) return;
  @shell_exec("taskkill /F /T /PID " . (int)$pid . " 2>NUL");
  @shell_exec("wmic process where ProcessId=" . (int)$pid . " call terminate 2>NUL");
}

function norm_out(string $s): string {
  $s = str_replace("\r\n", "\n", $s);
  $s = rtrim($s);
  $lines = explode("\n", $s);
  $lines = array_map(fn($l) => rtrim($l), $lines);
  return implode("\n", $lines);
}

function truncate_kb(string $s, int $kb): string {
  $max = $kb * 1024;
  if (strlen($s) <= $max) return $s;
  return substr($s, 0, $max) . "\n...[output truncated]...";
}

/**
 * Run a command with a hard timeout.
 * Windows+Apache can hang on proc_close sometimes.
 * Rule:
 * - If timed out -> kill -> close pipes -> return (NO proc_close)
 * - If normal end -> proc_close is OK
 */
function run_cmd(string $cmd, string $cwd, string $stdin, int $timeout_ms, int $output_limit_kb): array {
  $desc = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"],
  ];

 $cmd = trim((string)$cmd);

  // ✅ Windows safest way: escape the whole command for cmd.exe
  // This avoids "filename/directory/volume label syntax" errors caused by quoting.
  if (stripos(PHP_OS, "WIN") === 0) {
    $fullCmd = 'cmd /V:ON /S /C ' . escapeshellarg($cmd);
  } else {
    $fullCmd = $cmd;
  }



  $proc = @proc_open($fullCmd, $desc, $pipes, $cwd);
  if (!is_resource($proc)) {
    return [false, 127, "", "proc_open failed", false];
  }

  @fwrite($pipes[0], $stdin);
  @fclose($pipes[0]);

  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);

  $stdout = "";
  $stderr = "";
  $start = microtime(true);

  while (true) {
    $status = proc_get_status($proc);
    $running = (bool)($status["running"] ?? false);

    $stdout .= (string)@stream_get_contents($pipes[1]);
    $stderr .= (string)@stream_get_contents($pipes[2]);

    // output spam guard
    if (strlen($stdout) > $output_limit_kb * 1024 * 2) {
      $pid = (int)($status["pid"] ?? 0);
      @proc_terminate($proc);
      win_kill_tree($pid);

      @fclose($pipes[1]);
      @fclose($pipes[2]);

      return [true, 124, truncate_kb($stdout, $output_limit_kb), truncate_kb($stderr, $output_limit_kb), true];
    }

    if (!$running) break;

    $elapsed_ms = (int)((microtime(true) - $start) * 1000);
    if ($elapsed_ms > $timeout_ms) {
      $pid = (int)($status["pid"] ?? 0);
      @proc_terminate($proc);
      win_kill_tree($pid);

      @fclose($pipes[1]);
      @fclose($pipes[2]);

      return [true, 124, truncate_kb($stdout, $output_limit_kb), truncate_kb($stderr, $output_limit_kb), true];
    }

    usleep(20000);
  }

  $stdout .= (string)@stream_get_contents($pipes[1]);
  $stderr .= (string)@stream_get_contents($pipes[2]);

  @fclose($pipes[1]);
  @fclose($pipes[2]);

  $exit_code = @proc_close($proc);

  return [true, (int)$exit_code, truncate_kb($stdout, $output_limit_kb), truncate_kb($stderr, $output_limit_kb), false];
}

function load_problem_cases(int $problem_id, ?array $opts = null): array {
  $cfg = array_merge(judge_cfg(), $opts ?? []);
  $base = rtrim((string)$cfg["testcase_base_dir"], "/\\");
  $dir  = $base . DIRECTORY_SEPARATOR . "problem_" . (int)$problem_id;

  if (!is_dir($dir)) return [];
  $inputs = glob($dir . DIRECTORY_SEPARATOR . "in*.txt") ?: [];
  if (empty($inputs)) return [];

  usort($inputs, function($a, $b){
    $na = (int)preg_replace('/\D+/', '', basename($a));
    $nb = (int)preg_replace('/\D+/', '', basename($b));
    return $na <=> $nb;
  });

  $cases = [];
  foreach ($inputs as $inFile) {
    $n = (int)preg_replace('/\D+/', '', basename($inFile));
    if ($n <= 0) continue;

    $outFile = $dir . DIRECTORY_SEPARATOR . "out" . $n . ".txt";
    if (!is_file($outFile)) continue;

    $cases[] = [
      "in"  => (string)@file_get_contents($inFile),
      "out" => (string)@file_get_contents($outFile),
    ];
  }

  return $cases;
}

function local_judge(string $source, string $lang, array $cases, array $opts = []): array {
  $cfg = array_merge(judge_cfg(), $opts);
  ensure_dir($cfg["sandbox_dir"]);

  $globalStart = microtime(true);
  $totalLimitMs = (int)($cfg["total_limit_ms"] ?? 9000);

  $run_id = "run_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4));
  $workdir = rtrim($cfg["sandbox_dir"], "/\\") . DIRECTORY_SEPARATOR . $run_id;
  ensure_dir($workdir);

  $lang = strtolower(trim($lang));
  if (!in_array($lang, ["c", "cpp"], true)) {
    return ["verdict" => "CE", "compile_log" => "Unsupported language", "case_results" => []];
  }

  $src_file = $workdir . DIRECTORY_SEPARATOR . ($lang === "c" ? "main.c" : "main.cpp");
  file_put_contents($src_file, $source);

  $exe = $workdir . DIRECTORY_SEPARATOR . "a.exe";

  $compile_cmd = ($lang === "c")
    ? "\"{$cfg["gcc"]}\" \"{$src_file}\" -O2 -std=c11 -o \"{$exe}\""
    : "\"{$cfg["gpp"]}\" \"{$src_file}\" -O2 -std=c++17 -o \"{$exe}\"";

  [$ok, $exit, $cout, $cerr, $cto] = run_cmd(
    $compile_cmd,
    $workdir,
    "",
    (int)$cfg["compile_timeout_ms"],
    (int)$cfg["output_limit_kb"]
  );

  if (!$ok || $cto || $exit !== 0 || !file_exists($exe)) {
    return ["verdict" => "CE", "compile_log" => trim($cout . "\n" . $cerr), "case_results" => []];
  }

  if (empty($cases)) {
    return ["verdict" => "CE", "compile_log" => "No testcases found", "case_results" => []];
  }

  $case_results = [];
  $case_no = 0;

  foreach ($cases as $tc) {
    // ✅ global hard stop (prevents long spinning)
    $elapsedTotal = (int)((microtime(true) - $globalStart) * 1000);
    if ($elapsedTotal > $totalLimitMs) {
      return [
        "verdict" => "TLE",
        "compile_log" => "",
        "case_results" => $case_results,
        "first_fail" => ["case_no" => $case_no, "stderr" => "Global judge timeout"],
      ];
    }

    $case_no++;
    $inp = (string)($tc["in"] ?? "");
    $exp = (string)($tc["out"] ?? "");

    $t0 = microtime(true);
    [$rok, $rexit, $rout, $rerr, $rto] = run_cmd(
      "\"{$exe}\"",
      $workdir,
      $inp,
      (int)$cfg["time_limit_ms"],
      (int)$cfg["output_limit_kb"]
    );
    $ms = (int)((microtime(true) - $t0) * 1000);

    if (!$rok) {
      return ["verdict" => "RE", "compile_log" => "", "case_results" => $case_results,
        "first_fail" => ["case_no" => $case_no, "stderr" => "proc_open failed"]];
    }

    if ($rto || $rexit === 124) {
      return ["verdict" => "TLE", "compile_log" => "", "case_results" => $case_results,
        "first_fail" => ["case_no" => $case_no, "stderr" => "Time limit exceeded"]];
    }

    if ($rexit !== 0) {
      return ["verdict" => "RE", "compile_log" => "", "case_results" => $case_results,
        "first_fail" => ["case_no" => $case_no, "stderr" => $rerr]];
    }

    $got_n = norm_out($rout);
    $exp_n = norm_out($exp);

    $case_results[] = ["case_no" => $case_no, "time_ms" => $ms, "ok" => ($got_n === $exp_n)];

    if ($got_n !== $exp_n) {
      return ["verdict" => "WA", "compile_log" => "", "case_results" => $case_results,
        "first_fail" => ["case_no" => $case_no, "expected" => $exp_n, "got" => $got_n, "stderr" => $rerr]];
    }
  }

  return ["verdict" => "AC", "compile_log" => "", "case_results" => $case_results];
}





