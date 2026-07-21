<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default polling interval (minutes) for artisan schedule
    |--------------------------------------------------------------------------
    */
    'poll_interval_minutes' => (int) env('HIKVISION_POLL_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Shared secret for webhook validation (optional global fallback)
    |--------------------------------------------------------------------------
    */
    'webhook_secret' => env('HIKVISION_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Access-control minor codes treated as successful attendance punches
    | DS-K1T320: fingerprint/card/face verify success events
    |--------------------------------------------------------------------------
    */
    'attendance_minor_codes' => [
        1,   // legal card
        38,  // fingerprint verify pass
        39,  // fingerprint + card pass
        75,  // face verify pass
        76,  // face + card pass
        77,  // face + fingerprint pass
    ],

    /*
    |--------------------------------------------------------------------------
    | Major type for access control events
    |--------------------------------------------------------------------------
    */
    'access_major_code' => 5,

    /*
    |--------------------------------------------------------------------------
    | HTTP host index on device (1-3)
    |--------------------------------------------------------------------------
    */
    'http_host_id' => (int) env('HIKVISION_HTTP_HOST_ID', 1),
];
