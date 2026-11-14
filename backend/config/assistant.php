<?php

/*
 * In-product assistant, stage 3 (ADR-0017). Read-only; nothing is stored server-side.
 *
 * Installation choices (enabled, provider, model, budgets, redaction) are rows of the `assistant`
 * settings group, see SettingService::DEFAULTS. This file holds the technical limits and the
 * system-prompt version. The provider API key is never here: it comes from the environment only
 * (config/services.php, OPENAI_API_KEY).
 */
return [
    // File under resources/assistant/ sent as the system prompt. Bump the version when it changes.
    'system_prompt' => 'system-prompt.v3.md',

    // Model used when the setting assistant.model is blank. UNVERIFIED: model names were not
    // checked against the provider (no internet access when this was written); set the setting.
    'default_model' => env('ASSISTANT_DEFAULT_MODEL', 'gpt-4o-mini'),

    // Defaults of the optional settings assistant.max_tool_calls_per_turn, max_output_tokens,
    // daily_token_budget and rate_limit_per_minute (a stored setting overrides each of them).
    'max_tool_calls_per_turn' => (int) env('ASSISTANT_MAX_TOOL_CALLS_PER_TURN', 6),
    'max_output_tokens' => (int) env('ASSISTANT_MAX_OUTPUT_TOKENS', 1024),
    // Tokens (prompt + completion) one user may spend per day.
    'daily_token_budget' => (int) env('ASSISTANT_DAILY_TOKEN_BUDGET', 200000),
    // Chat requests per user per minute (named limiter assistant-chat).
    'rate_limit_per_minute' => (int) env('ASSISTANT_RATE_LIMIT_PER_MINUTE', 20),

    // Conversation limits (the client holds the conversation; the server is stateless).
    'max_messages' => 40,
    'max_message_chars' => 4000,

    // Seconds to wait for the provider before the turn fails with assistant_provider_error.
    'provider_timeout' => (int) env('ASSISTANT_PROVIDER_TIMEOUT', 60),

    // A tool result sent to the model is capped at this many bytes (largest list halved until it fits).
    'max_tool_result_bytes' => (int) env('ASSISTANT_MAX_TOOL_RESULT_BYTES', 32 * 1024),

    // Final answer length cap in characters.
    'max_answer_chars' => 8000,
];
