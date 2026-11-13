<?php

/*
 * MCP gateway stages 1-2 (ADR-0016). Read-only tokens for the external gateway.
 */
return [
    // Default and maximum lifetime of an MCP token, in minutes (hard cap 24h).
    'token_default_minutes' => min((int) env('MCP_TOKEN_DEFAULT_MINUTES', 480), 1440),
    'token_max_minutes' => min((int) env('MCP_TOKEN_MAX_MINUTES', 1440), 1440),

    // Active MCP tokens one user may hold at the same time.
    'max_tokens_per_user' => (int) env('MCP_MAX_TOKENS_PER_USER', 10),

    // Requests per minute per MCP token (named limiter "mcp-read").
    'rate_limit_per_minute' => (int) env('MCP_RATE_LIMIT_PER_MINUTE', 60),

    // Report tools (party statement, aging, trial balance, income statement): requests per minute per token.
    'report_rate_limit_per_minute' => (int) env('MCP_REPORT_RATE_LIMIT_PER_MINUTE', 6),

    // Rows a token may read per day (counted from the response data). The setting
    // mcp.daily_row_budget overrides this value; the result is a 429 mcp_budget_exceeded.
    'daily_row_budget' => (int) env('MCP_DAILY_ROW_BUDGET', 5000),

    // Bulk-read detection: this many list pages of at least `bulk_page_min_rows` rows inside
    // `bulk_window_seconds` raise one mcp.bulk_read audit event per window (the budget blocks).
    'bulk_pages' => (int) env('MCP_BULK_PAGES', 10),
    'bulk_page_min_rows' => (int) env('MCP_BULK_PAGE_MIN_ROWS', 20),
    'bulk_window_seconds' => (int) env('MCP_BULK_WINDOW_SECONDS', 300),
];
