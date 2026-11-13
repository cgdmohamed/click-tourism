<?php

/**
 * Document attachments (ADR-0021). Files are stored on a private disk under random names and are
 * only served through authenticated endpoints.
 */
return [
    'disk' => env('ATTACHMENTS_DISK', 'local'),
    'max_size_kb' => (int) env('ATTACHMENTS_MAX_KB', 10240),
    'max_files_per_upload' => (int) env('ATTACHMENTS_MAX_FILES', 10),
    // Content-sniffed, never trusted from the client: mime => stored extension.
    'allowed' => [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],
    // Bytes of a document sent to the provider for extraction (documents above this are refused).
    'extraction_max_kb' => (int) env('ATTACHMENTS_EXTRACTION_MAX_KB', 8192),
];
