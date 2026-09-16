<?php

return [
    'allowed_source_roots' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AUDIT_SOURCE_ROOTS', base_path('targets'))),
    ))),
    'event_payload_bytes' => (int) env('AUDIT_EVENT_PAYLOAD_BYTES', 65536),
    'sse_window_seconds' => (int) env('AUDIT_SSE_WINDOW_SECONDS', 20),
    'imports' => [
        'compressed_bytes' => (int) env('SOURCE_IMPORT_COMPRESSED_BYTES', 100 * 1024 * 1024),
        'extracted_bytes' => (int) env('SOURCE_IMPORT_EXTRACTED_BYTES', 1024 * 1024 * 1024),
        'files' => (int) env('SOURCE_IMPORT_FILES', 50000),
        'retention_days' => (int) env('SOURCE_IMPORT_RETENTION_DAYS', 7),
    ],
    'doctor' => [
        'timeout' => (int) env('PREPARATION_DOCTOR_TIMEOUT', 1800),
        'model' => env('PREPARATION_DOCTOR_MODEL'),
    ],
];
