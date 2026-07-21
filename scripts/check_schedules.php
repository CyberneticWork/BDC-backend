<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = app(\App\Http\Controllers\ReportController::class)
    ->getMonthlyReportData(new \Illuminate\Http\Request(['month' => '07', 'year' => '2026']))
    ->getData(true);

$s = $rows[0];
echo "staff_monthly={$s['staff_fund_monthly']} ytd_paid={$s['staff_fund_ytd_paid']} ytd_contrib={$s['staff_fund_ytd_contribution']}\n";
echo "basic_nopay_days={$s['basic_nopay_days']} allowance_nopay_days={$s['allowance_nopay_days']}\n";
echo "employees=" . count($rows) . " with_staff_fund=" . count(array_filter($rows, fn($e) => $e['staff_fund_monthly'] > 0)) . "\n";
