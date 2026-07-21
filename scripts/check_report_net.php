<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$controller = app(\App\Http\Controllers\ReportController::class);
$rows = $controller->getMonthlyReportData(new \Illuminate\Http\Request(['month' => '07', 'year' => '2026']))->getData(true);

$issues = 0;
foreach ($rows as $e) {
    $name = $e['name'];

    // Sch01 must not include monthly bonus in total
    $sch01WithoutBonus = round($e['base_basic_salary'] + $e['budgetary_allowance'] + $e['budget_relief_allowance'] + ($e['increment_amount'] ?? 0), 2);
    if (abs($e['total_salary_sch01'] - ($e['salary_component'] + ($e['monthly_bonus'] ?? 0))) < 0.02 && ($e['monthly_bonus'] ?? 0) > 0) {
        echo "[SCH01] {$name}: total incorrectly includes monthly bonus\n";
        $issues++;
    }

    // Sch01 total should match salary component
    if (abs($e['total_salary_sch01'] - $e['salary_component']) > 0.02) {
        echo "[SCH01] {$name}: total={$e['total_salary_sch01']} != salary_component={$e['salary_component']}\n";
        $issues++;
    }

    // BR should be present when compensation has BR
    if ($e['budget_relief_allowance'] <= 0 && $e['salary_for_epf'] > $e['base_basic_salary']) {
        echo "[SCH01] {$name}: missing BR (epf base {$e['salary_for_epf']} > raw {$e['base_basic_salary']})\n";
        $issues++;
    }

    // Net pay from payroll
    if (abs($e['total_report_net'] - $e['net_salary']) > 0.02) {
        echo "[NET] {$name}: report net != payroll net\n";
        $issues++;
    }

    // Track split
    $split = round($e['epf_schedule_net'] + $e['allowance_net'], 2);
    if (abs($split - $e['net_salary']) > 0.02) {
        echo "[SPLIT] {$name}: epf({$e['epf_schedule_net']}) + allow({$e['allowance_net']}) = {$split} != {$e['net_salary']}\n";
        $issues++;
    }

    // Column sum on total report
    $colSum = round(
        $e['no_pay_amount'] + $e['salary_advance'] + $e['loan_installment'] + $e['loan_interest']
        + $e['sports_fund'] + $e['epf_8'] + $e['staff_fund'] + $e['other_deduction'],
        2
    );
    if (abs($colSum - $e['total_deductions']) > 0.02) {
        echo "[COLS] {$name}: column sum {$colSum} != total_ded {$e['total_deductions']}\n";
        $issues++;
    }
}

echo "\nChecked " . count($rows) . " employees, {$issues} issue(s).\n";
if ($issues === 0 && !empty($rows)) {
    $s = $rows[0];
    echo "\nSample ({$s['name']}):\n";
    echo "  Sch01: basic={$s['base_basic_salary']} budgetary={$s['budgetary_allowance']} BR={$s['budget_relief_allowance']} total={$s['total_salary_sch01']}\n";
    echo "  Salary comp={$s['salary_component']} Allowance={$s['allowance_component']} Gross={$s['gross_salary']}\n";
    echo "  Deductions={$s['total_deductions']} Net={$s['net_salary']} Bank={$s['bank_amount']}\n";
    echo "  EPF net={$s['epf_schedule_net']} Allow net={$s['allowance_net']}\n";
}

exit($issues > 0 ? 1 : 0);
