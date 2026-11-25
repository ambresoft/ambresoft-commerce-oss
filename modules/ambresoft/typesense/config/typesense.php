<?php

/*
 * Connection to the Typesense server (deployment configuration, never merchant settings). The server is a separate
 * service: the bundled Docker Compose service locally, or any external Typesense endpoint. Use an admin key only on the
 * server; it never reaches the browser.
 */
return [
    // Comma-separated base URLs of a Typesense cluster (e.g. "https://ts1.example.com:443,https://ts2.example.com:443");
    // when empty, protocol://host:port is the only node.
    'nodes' => env('TYPESENSE_NODES', ''),
    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
    'host' => env('TYPESENSE_HOST', ''),
    'port' => (int) env('TYPESENSE_PORT', 8108),
    'path' => env('TYPESENSE_PATH', ''),
    'api_key' => env('TYPESENSE_API_KEY', ''),
    // Seconds. Storefront searches use `timeout`; imports use `import_timeout`.
    'connect_timeout' => (float) env('TYPESENSE_CONNECT_TIMEOUT', 2),
    'timeout' => (float) env('TYPESENSE_TIMEOUT', 3),
    'import_timeout' => (float) env('TYPESENSE_IMPORT_TIMEOUT', 60),
    // Extra attempts after a connection error, 429 or 5xx answer (storefront searches retry at most once).
    'retries' => (int) env('TYPESENSE_RETRIES', 2),
    'retry_ms' => (int) env('TYPESENSE_RETRY_MS', 200),
    // The alias every search uses; physical collections are "{alias}_{layout}_{time}" behind it.
    'alias' => env('TYPESENSE_COLLECTION', 'products'),
];
