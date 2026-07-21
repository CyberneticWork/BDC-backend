<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$controller = app(\App\Http\Controllers\ReportController::class);
$rows = $controller->getMonthlyReportData(new \Illuminate\Http\Request(['month' => '07', 'year' => '2026']))->getData(true);

$epfEligible = 0;
foreach ($rows as $e) {
    $enabled = $e['enable_epf_etf'] ?? 0;
    $contrib = ($e['epf_8'] ?? 0) + ($e['epf_12'] ?? 0);
    if ($enabled && $contrib > 0) {
        $epfEligible++;
    }
}

echo 'total=' . count($rows) . ' epfEligible=' . $epfEligible . PHP_EOL;

if (!empty($rows)) {
    $s = $rows[0];
    echo 'sample enable=' . var_export($s['enable_epf_etf'], true) . ' epf8=' . $s['epf_8'] . ' epf12=' . $s['epf_12'] . PHP_EOL;
    echo 'strict ===1 match=' . ($s['enable_epf_etf'] === 1 ? 'yes' : 'no') . PHP_EOL;
}
