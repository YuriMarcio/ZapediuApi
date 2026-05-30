<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'public/*', 'auth/*', 'admin/*', 'tenant/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'https://localhost:5173'),
        'https://localhost:5173',
        'https://localhost:5174',
        'https://localhost:8080',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
