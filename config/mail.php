<?php

return [
    'default' => env('MAIL_MAILER', 'smtp'),
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp', 'scheme' => env('MAIL_SCHEME'), 'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'), 'port' => env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME'), 'password' => env('MAIL_PASSWORD'),
            'timeout' => env('MAIL_TIMEOUT'), 'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],
        'array' => ['transport' => 'array'],
        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
    ],
    'from' => ['address' => env('MAIL_FROM_ADDRESS', 'ifc@example.test'), 'name' => env('MAIL_FROM_NAME', 'InterFreight Central')],
    'markdown' => ['theme' => 'default', 'paths' => [resource_path('views/vendor/mail')]],
];
