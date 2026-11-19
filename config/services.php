<?php

return [
    'ai' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('AI_SERVICE_TOKEN'),
        'connect_timeout' => env('AI_SERVICE_CONNECT_TIMEOUT', 3),
        'timeout' => env('AI_SERVICE_TIMEOUT', 10),
        'verify_tls' => env('AI_SERVICE_VERIFY_TLS', true),
        'ca_bundle' => env('AI_SERVICE_CA_BUNDLE'),
        'credential_url' => env('AI_LARAVEL_CREDENTIAL_URL'),
    ],
];
