<?php

return [
    'check_seconds' => (int) env('ADMIN_SSE_CHECK_SECONDS', 3),
    'heartbeat_seconds' => (int) env('ADMIN_SSE_HEARTBEAT_SECONDS', 10),
    'lifetime_seconds' => (int) env('ADMIN_SSE_LIFETIME_SECONDS', 25),
    'retry_ms' => (int) env('ADMIN_SSE_RETRY_MS', 10000),
    // Global PostgreSQL advisory-lock slots; leave workers free for normal HTTP requests.
    'max_connections' => (int) env('ADMIN_SSE_MAX_CONNECTIONS', 4),
];
