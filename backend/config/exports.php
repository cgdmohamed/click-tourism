<?php

/**
 * Background report exports (ADR-0025, GAP-RPT-02). A job re-runs the normal export route as the
 * user, so permissions, branch scope, filters and the audit row are those of the synchronous export.
 */
return [
    'disk' => env('EXPORTS_DISK', 'local'),
    // Days a finished file stays downloadable; `exports:prune` deletes it afterwards.
    'ttl_days' => (int) env('EXPORTS_TTL_DAYS', 7),
    // Row cap of a background run (the synchronous routes stay at 50,000).
    'async_max_rows' => (int) env('EXPORTS_ASYNC_MAX_ROWS', 200000),
    // Seconds a job may run before the queue stops it.
    'timeout' => (int) env('EXPORTS_TIMEOUT', 600),
    // Report key => export route below /api/v1 (every route exists and is permission-checked on its own).
    'reports' => [
        'trial-balance', 'general-ledger', 'income-statement', 'balance-sheet', 'aging', 'aging-reconciliation',
        'party-statement', 'profitability', 'sales', 'commissions', 'tax', 'cost-center-pl', 'cost-center-unallocated',
    ],
];
