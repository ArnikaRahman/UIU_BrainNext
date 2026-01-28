<?php
/**
 * One-time installer for the META DB (uiu_brainnext_meta)
 * Creates DB + tables used by AI cache + audit trail.
 *
 * Usage:
 *   http://localhost/uiu_brainnext/tools/setup_meta_db.php
 */

header("Content-Type: text/plain; charset=utf-8");

$host = "127.0.0.1";
$port = 3306;      // change if your MySQL uses 3307
$user = "root";
$pass = "";
$db = "uiu_brainnext_meta";

mysqli_report(MYSQLI_REPORT_OFF);

$root = new mysqli($host, $user, $pass, "", $port);
if ($root->connect_error) {
  http_response_code(500);
  echo "MySQL connect failed: " . $root->connect_error . "\n";
  exit;
}

if (!$root->query("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
  http_response_code(500);
  echo "Failed to create DB: " . $root->error . "\n";
  exit;
}

$meta = new mysqli($host, $user, $pass, $db, $port);
if ($meta->connect_error) {
  http_response_code(500);
  echo "Meta DB connect failed: " . $meta->connect_error . "\n";
  exit;
}
$meta->set_charset("utf8mb4");

$queries = [];

// 1) Cache of full response text (for speed + rate limiting)
$queries[] = "
CREATE TABLE IF NOT EXISTS ai_cache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(32) NOT NULL,
  model VARCHAR(128) NOT NULL,
  prompt_sha256 CHAR(64) NOT NULL,
  response_sha256 CHAR(64) NOT NULL,
  response_text MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cache (kind, model, prompt_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// 2) Legacy logs (optional, but your project has code that can write here)
$queries[] = "
CREATE TABLE IF NOT EXISTS ai_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NULL,
  role VARCHAR(32) NOT NULL,
  kind VARCHAR(32) NOT NULL,
  model VARCHAR(128) NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT NULL,
  entity_label VARCHAR(255) NULL,
  prompt_sha256 CHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL,
  latency_ms INT NOT NULL DEFAULT 0,
  response_sha256 CHAR(64) NULL,
  snippet VARCHAR(180) NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(180) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_entity (entity_type, entity_id),
  KEY idx_user (user_id),
  KEY idx_kind (kind),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// 3) Main explainable + logged audit trail
$queries[] = "
CREATE TABLE IF NOT EXISTS ai_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NULL,
  role VARCHAR(32) NOT NULL,
  action VARCHAR(32) NOT NULL,
  model VARCHAR(128) NOT NULL,
  target_type VARCHAR(32) NOT NULL,
  target_id INT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'ok',
  latency_ms INT NOT NULL DEFAULT 0,
  prompt_sha256 CHAR(64) NOT NULL,
  response_sha256 CHAR(64) NULL,
  response_preview VARCHAR(255) NOT NULL DEFAULT '',
  token_est INT NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  error_text TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_target (target_type, target_id),
  KEY idx_user (user_id),
  KEY idx_action (action),
  KEY idx_status (status),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

foreach ($queries as $q) {
  if (!$meta->query($q)) {
    http_response_code(500);
    echo "FAILED:\n" . $meta->error . "\n\n";
    echo "QUERY:\n" . $q . "\n";
    exit;
  }
}

echo "OK: Meta DB installed\n";