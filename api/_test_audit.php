<?php
require_once __DIR__ . "/../includes/ai_meta_store.php";

ai_audit_log(
  1, "student", "hint", "debug_model",
  "problem", 2, ai_sha256("prompt"),
  "DBG", "ok", 123,
  ai_sha256("resp"), "preview", 10, ""
);

echo "done";

