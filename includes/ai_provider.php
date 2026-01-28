<?php
require_once __DIR__ . "/ai_config.php";
require_once __DIR__ . "/ollama_ai.php";

/**
 * Unified AI call (Ollama only)
 * Returns: ["ok"=>bool, "text"=>string, "latency_ms"=>int, "model"=>string, "error"=>string]
 */
function ai_infer_text(string $kind, string $prompt, array $opts = []): array {
  $model = defined("OLLAMA_MODEL_HINT") ? OLLAMA_MODEL_HINT : "phi3:mini";

  if ($kind === "feedback") {
    $model = defined("OLLAMA_MODEL_FEEDBACK") ? OLLAMA_MODEL_FEEDBACK : $model;
  } elseif ($kind === "codecheck") {
    $model = defined("OLLAMA_MODEL_CODECHECK") ? OLLAMA_MODEL_CODECHECK : $model;
  }

  return ollama_infer_text($model, $prompt, $opts);
}



