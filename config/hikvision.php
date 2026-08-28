<?php

return [
    'poll_interval_minutes' => (int) env('HIKVISION_POLL_INTERVAL', 5),

    'webhook_secret' => env('HIKVISION_WEBHOOK_SECRET'),

    /*
    | Force modes (Solar parity):
    | HIKVISION_LAN_MODE=1          — always attempt direct ISAPI
    | HIKVISION_FORCE_CLOUD_BRIDGE=1 — never call LAN; use office bridge only
    */
    'force_lan_mode' => env('HIKVISION_LAN_MODE', false),
    'force_cloud_bridge' => env('HIKVISION_FORCE_CLOUD_BRIDGE', false),

    'attendance_minor_codes' => [
        1,   // legal card
        38,  // fingerprint verify pass (DS-K1T320)
        39,  // fingerprint + card pass
        75,  // face verify pass
        76,  // face + card pass
        77,  // face + fingerprint pass
    ],

    'access_major_code' => 5,

    /*
    | AcsEvent query combinations used by Sync (Solar DS-K1T320 working set)
    */
    'acs_event_queries' => [
        ['major' => 5, 'minor' => 38],
        ['major' => 5, 'minor' => 1],
        ['major' => 5, 'minor' => 75],
        ['major' => 5, 'minor' => 76],
        ['major' => 0, 'minor' => 0],
    ],

    'http_host_id' => (int) env('HIKVISION_HTTP_HOST_ID', 1),
];
