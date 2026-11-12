<?php

/*
 * MCP gateway stage 1 (ADR-0016). Read-only tokens for the external gateway.
 */
return [
    // Default and maximum lifetime of an MCP token, in minutes (hard cap 24h).
    'token_default_minutes' => min((int) env('MCP_TOKEN_DEFAULT_MINUTES', 480), 1440),
    'token_max_minutes' => min((int) env('MCP_TOKEN_MAX_MINUTES', 1440), 1440),

    // Active MCP tokens one user may hold at the same time.
    'max_tokens_per_user' => (int) env('MCP_MAX_TOKENS_PER_USER', 10),

    // Requests per minute per MCP token (named limiter "mcp-read").
    'rate_limit_per_minute' => (int) env('MCP_RATE_LIMIT_PER_MINUTE', 60),
];
