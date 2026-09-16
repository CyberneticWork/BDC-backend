<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY')),
    'ttl' => (int) env('JWT_TTL', 28800),
    'algo' => 'HS256',
    'issuer' => env('APP_URL', 'hr-api'),
];
