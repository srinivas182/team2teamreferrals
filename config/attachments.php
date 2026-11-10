<?php

return [
    // ClamAV daemon (compose.prod.yaml runs one as "clamav"). Empty = no scanner.
    'clamav_host' => env('CLAMAV_HOST'),
    'clamav_port' => (int) env('CLAMAV_PORT', 3310),
    // When on, files can only be opened once scanned clean. Keep it on in production.
    'require_scan' => (bool) env('ATTACHMENTS_REQUIRE_SCAN', true),
    'max_kb' => 10240,
    'max_files' => 3,
    'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'],
];
