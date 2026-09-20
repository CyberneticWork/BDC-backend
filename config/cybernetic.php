<?php

return [
    'admin_password' => (string) env('CYBERNETIC_ADMIN_PASSWORD', ''),
    'super_admin_email' => (string) env('SUPER_ADMIN_EMAIL', 'superadmin@cybernetic.local'),
    'super_admin_password' => (string) env('SUPER_ADMIN_PASSWORD', ''),
    'token_secret' => env('CYBERNETIC_ADMIN_TOKEN_SECRET', env('APP_KEY')),
    'token_ttl_hours' => (int) env('CYBERNETIC_ADMIN_TOKEN_TTL_HOURS', 168),
    'login_max_attempts' => (int) env('CYBERNETIC_ADMIN_LOGIN_ATTEMPTS', 6),
    'login_decay_minutes' => (int) env('CYBERNETIC_ADMIN_LOGIN_LOCK_MINUTES', 15),
];
