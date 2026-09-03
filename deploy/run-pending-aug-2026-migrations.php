<?php

/**
 * Run only the pending August 2026 migrations on live/production.
 * Safe when full `php artisan migrate` fails due to older migration history drift.
 *
 * Usage (on API server, from project root):
 *   php deploy/run-pending-aug-2026-migrations.php
 */

declare(strict_types=1);

$paths = [
    'database/migrations/2026_08_27_100000_add_status_to_rosters_table.php',
    'database/migrations/2026_08_27_233000_create_employee_leave_balances_table.php',
    'database/migrations/2026_08_28_000001_create_salary_advance_requests_table.php',
    'database/migrations/2026_09_03_100000_create_monthly_late_deduction_tables.php',
];

echo "Running pending August 2026 migrations...\n\n";

foreach ($paths as $path) {
    echo "==> {$path}\n";
    passthru('php artisan migrate --path=' . escapeshellarg($path) . ' --force', $code);
    if ($code !== 0) {
        echo "\nMigration failed for {$path} (exit {$code}).\n";
        exit($code);
    }
    echo "\n";
}

echo "Done.\n";
