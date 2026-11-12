<?php

/*
 | Generic import framework limits (work order 13). Every value can be
 | tuned per installation through the environment.
 */
return [
    // Private storage disk for uploaded sources and error reports.
    'disk' => env('IMPORTS_DISK', 'local'),

    // Hard upload limit in bytes (default 5 MB).
    'max_file_bytes' => (int) env('IMPORTS_MAX_FILE_BYTES', 5 * 1024 * 1024),

    // Maximum data rows per file (header excluded).
    'max_rows' => (int) env('IMPORTS_MAX_ROWS', 10000),

    // Maximum columns read per row and characters per cell.
    'max_columns' => (int) env('IMPORTS_MAX_COLUMNS', 60),
    'max_cell_length' => (int) env('IMPORTS_MAX_CELL_LENGTH', 2000),

    // Files at or below this size are processed inside the request; larger
    // files are handed to the queue worker.
    'sync_max_bytes' => (int) env('IMPORTS_SYNC_MAX_BYTES', 100 * 1024),

    // Rows kept on the job for the preview grid / inline error list. The full
    // error list is always available in the downloadable report.
    'preview_rows' => 50,
    'inline_errors' => 200,

    // Queue timeout (seconds) for a background import.
    'job_timeout' => (int) env('IMPORTS_JOB_TIMEOUT', 900),
];
