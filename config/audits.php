<?php

return [
    'allowed_source_roots' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AUDIT_SOURCE_ROOTS', base_path('targets'))),
    ))),
    'event_payload_bytes' => (int) env('AUDIT_EVENT_PAYLOAD_BYTES', 65536),
    'sse_window_seconds' => (int) env('AUDIT_SSE_WINDOW_SECONDS', 20),
];
