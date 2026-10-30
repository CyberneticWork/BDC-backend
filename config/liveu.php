<?php

return [
    'base_url' => rtrim((string) env('LIVEU_API_BASE_URL', 'https://attapi.liveucloud.com/api/v1'), '/'),
    'poll_interval_minutes' => max(1, (int) env('LIVEU_POLL_INTERVAL', 5)),
    // Only import Granted access events into Time Card
    'import_access' => env('LIVEU_IMPORT_ACCESS', 'Granted'),
];
