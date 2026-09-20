<?php

return [
    'paths' => ['*', 'api/*', 'index.php/*', 'index.php', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)*cyberneticde\.site$#i',
        '#^https://bdchrnew\.cyberneticde\.site$#i',
        '#^https://apibdchrnew\.cyberneticde\.site$#i',
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,
];
