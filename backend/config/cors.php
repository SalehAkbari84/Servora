<?php

// Allowed browser origins for the API. In production set FRONTEND_URL (and
// optionally FRONTEND_URLS for a comma-separated list) in .env to your real
// frontend domain(s). Falls back to the local dev frontend.
$origins = array_values(array_filter(array_map('trim', array_merge(
    [env('FRONTEND_URL', 'http://localhost:3000')],
    explode(',', (string) env('FRONTEND_URLS', '')),
))));

return [
    'paths'                    => ['api/*'],
    'allowed_methods'          => ['*'],
    'allowed_origins'          => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['*'],
    'exposed_headers'          => [],
    'max_age'                  => 0,
    'supports_credentials'     => true,
];
