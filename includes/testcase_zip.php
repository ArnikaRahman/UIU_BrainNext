<?php
/**
 * Safe ZIP extractor for judge testcases
 * Extracts ZIP into /judge/testcases/test_{id}/
 *
 * Accepts formats:
 *  1) inputs/* and outputs/* (any extensions; must match by basename)
 *  2) root: 1.in 1.out 2.in 2.out ...
 */

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
}

function rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  foreach ($items as $it) {
    if ($it === "." || $it === "..") continue;
    $p = $dir . DIRECTORY_SEPARATOR . $it;
    if (is_dir($p)) rrmdir($p);
    else @unlink($p);
  }
  @rmdir($dir);
}

/** prevent zip slip */
function zip_entry_is_safe(string $name): bool {
  $name = str_replace("\\", "/", $name);
  if (strpos($name, "../") !== false) return false;
  if (str_starts_with($name, "/")) return false;
  if (preg_match('/^[A-Za-z]:\//', $name)) return false;
  return true;
}

/**
 * Main extractor
 * @return array [bool ok, string message]
 */
function extract_testcase_zip(string $tmpZipPath, string $destDir): array {
  if (!class_exists("ZipArchive")) {
    return [false, "ZipArchive not available in PHP runtime (enable php_zip extension)."];
  }
  if (!file_exists($tmpZipPath)) {
    return [false, "Uploaded temp ZIP not found."];
  }

  // clean old folder if exists
  if (is_dir($destDir)) rrmdir($destDir);
  ensure_dir($destDir);

  $za = new ZipArchive();
  $ok = $za->open($tmpZipPath);
  if ($ok !== true) {
    rrmdir($destDir);
    return [false, "Failed to open zip. Code: " . $ok];
  }

  // safe extract (manual)
  for ($i=0; $i<$za->numFiles; $i++) {
    $stat = $za->statIndex($i);
    $name = (string)($stat["name"] ?? "");
    if ($name === "") continue;

    if (!zip_entry_is_safe($name)) {
      $za->close();
      rrmdir($destDir);
      return [false, "Unsafe entry in zip: " . $name];
    }

    // directory
    if (str_ends_with($name, "/")) {
      ensure_dir($destDir . DIRECTORY_SEPARATOR . $name);
      continue;
    }

    $target = $destDir . DIRECTORY_SEPARATOR . $name;
    ensure_dir(dirname($target));

    $stream = $za->getStream($name);
    if (!$stream) continue;

    $out = fopen($target, "wb");
    while (!feof($stream)) fwrite($out, fread($stream, 8192));
    fclose($out);
    fclose($stream);
  }
  $za->close();

  // Validate structure
  $inputsDir  = $destDir . DIRECTORY_SEPARATOR . "inputs";
  $outputsDir = $destDir . DIRECTORY_SEPARATOR . "outputs";

  $hasIO = is_dir($inputsDir) && is_dir($outputsDir);

  // root style
  $hasIn = (glob($destDir . DIRECTORY_SEPARATOR . "*.in") ?: []);
  $hasOut= (glob($destDir . DIRECTORY_SEPARATOR . "*.out") ?: []);

  if (!$hasIO && (count($hasIn) === 0 || count($hasOut) === 0)) {
    rrmdir($destDir);
    return [false, "ZIP structure invalid. Use inputs/outputs OR 1.in/1.out style."];
  }

  return [true, "Extracted to: " . $destDir];
}

/**
 * Compatibility alias (your teacher_create_test stacktrace shows this name)
 */
function extract_testcase_zip_strict(string $tmpZipPath, string $destDir): array {
  return extract_testcase_zip($tmpZipPath, $destDir);
}


