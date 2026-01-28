<?php
/**
 * AI configuration for UIU BrainNext
 * Provider: Ollama (local) + Gemma
 *
 * Recommended lightweight model for code-check/feedback:
 *   gemma3:4b-it-q4_K_M  (good quality, still manageable on many laptops)
 * If your PC is weak:
 *   gemma3:1b (smaller, lower quality)
 */

define("AI_PROVIDER", "ollama");

/* ---------------- OLLAMA SETTINGS ---------------- */
define("OLLAMA_URL", "http://127.0.0.1:11434/api/generate");

define("OLLAMA_MODEL_HINT", "phi3:mini");
define("OLLAMA_MODEL_FEEDBACK", "phi3:mini");
define("OLLAMA_MODEL_CODECHECK", "phi3:mini");

/* ---------------- Limits ---------------- */
define("AI_MAX_CODE_CHARS", 8000);

/* ---------------- Generation defaults ---------------- */
// Hint
define("AI_TEMP_HINT", 0.2);
define("AI_MAX_TOKENS_HINT", 260);

// Feedback
define("AI_TEMP_FEEDBACK", 0.2);
define("AI_MAX_TOKENS_FEEDBACK", 420);

// Code Check (strict format)
define("AI_TEMP_CODECHECK", 0.0);
define("AI_MAX_TOKENS_CODECHECK", 220);

/* ---------------- Daily AI usage limits (per student) ---------------- */
define("AI_LIMIT_HINT_DAILY", 2);
define("AI_LIMIT_FEEDBACK_DAILY", 10);
define("AI_LIMIT_CODECHECK_DAILY", 15);













