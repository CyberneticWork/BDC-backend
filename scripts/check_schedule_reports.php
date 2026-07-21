<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$data = app(\App\Services\ScheduleReportService::class)->build('07', '2026');
echo 'company=' . ($data['company']['name'] ?? '?') . ' reg=' . ($data['company']['registration_no'] ?? '?') . PHP_EOL;
echo '07a employees=' . count($data['staff_fund_detail_07a']['employees'] ?? []) . PHP_EOL;
echo 'fy=' . ($data['staff_fund_detail_07a']['fiscal_year'] ?? '?') . PHP_EOL;
if (!empty($data['staff_fund_detail_07a']['employees'][0])) {
    $e = $data['staff_fund_detail_07a']['employees'][0];
    echo 'sample months=' . count($e['employee_rows']) . ' emp_total=' . $e['employee_footer']['employee_total'] . PHP_EOL;
}
