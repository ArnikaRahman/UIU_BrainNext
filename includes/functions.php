<?php
date_default_timezone_set('Asia/Dhaka');
if (session_status() === PHP_SESSION_NONE) session_start();

function e($str): string {
  return htmlspecialchars((string)$str, ENT_QUOTES, "UTF-8");
}

function redirect(string $path): void {
  header("Location: " . $path);
  exit;
}

function set_flash(string $key, string $msg): void {
  $_SESSION["flash"][$key] = $msg;
}

function get_flash(string $key): ?string {
  if (!isset($_SESSION["flash"][$key])) return null;
  $v = $_SESSION["flash"][$key];
  unset($_SESSION["flash"][$key]);
  return $v;
}


