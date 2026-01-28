<?php
/**
 * Local Ollama inference (NO Hugging Face)
 * Requires: ollama running on localhost:11434
 */

if (!defined("OLLAMA_URL")) {
  define("OLLAMA_URL", "http://127.0.0.1:11434/api/generate");
}

function ollama_infer_text(string $model, string $prompt, array $opts = []): array {
  $url = OLLAMA_URL;

  $payload = [
    "model" => $model,
    "prompt" => $prompt,
    "stream" => false,
    "options" => [
      "temperature" => $opts["temperature"] ?? 0.2,
      "num_predict" => $opts["max_tokens"] ?? 300,
    ]
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 120,
  ]);

  $t0 = microtime(true);
  $raw = curl_exec($ch);
  $lat = (int)((microtime(true) - $t0) * 1000);

  if ($raw === false) {
    $errno = curl_errno($ch);
    $err   = curl_error($ch);

    // Connection / offline style errors
    $offlineErrnos = [6, 7, 28]; // 6=resolve, 7=connect, 28=timeout
    $isOffline = in_array($errno, $offlineErrnos, true);

    return [
      "ok" => false,
      "error" => "Ollama curl error ($errno): " . $err,
      "error_code" => $isOffline ? "OLLAMA_OFFLINE" : "OLLAMA_CURL_ERROR",
      "latency_ms" => $lat
    ];
  }

  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data["response"])) {
    return [
      "ok" => false,
      "error" => "Invalid Ollama response: " . substr($raw, 0, 200),
      "latency_ms" => $lat
    ];
  }

  return [
    "ok" => true,
    "text" => trim((string)$data["response"]),
    "latency_ms" => $lat,
    "model" => $model
  ];
}

/**
 * Backward-compat alias (so older code calling ollama_generate() still works)
 */
function ollama_generate(string $model, string $prompt, array $opts = []): array {
  return ollama_infer_text($model, $prompt, $opts);
}




