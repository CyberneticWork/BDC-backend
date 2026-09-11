<?php

return [
    'admin_password' => env('CYBERNETIC_ADMIN_PASSWORD', ''),
    'token_secret' => env('CYBERNETIC_ADMIN_TOKEN_SECRET', env('APP_KEY')),
    'token_ttl_hours' => (int) env('CYBERNETIC_ADMIN_TOKEN_TTL_HOURS', 12),
];
