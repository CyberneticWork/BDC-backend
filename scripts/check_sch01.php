<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = \App\Models\salary_process::with('employee.compensation')
    ->where('year', '2026')->whereIn('month', ['07', 7])->first();

$c = $s->employee->compensation;
echo "raw_basic={$s->basic_salary} br1={$s->br1} br2={$s->br2}\n";
echo "comp_basic={$c->basic_salary} comp_br1={$c->br1} comp_br2={$c->br2} monthly_bonus={$c->monthly_bonus}\n";

$b = is_string($s->salary_breakdown) ? json_decode($s->salary_breakdown, true) : $s->salary_breakdown;
echo "breakdown basic={$b['basic_salary']} gross={$b['gross_salary']} net={$b['net_salary']} total_ded={$b['total_deductions']}\n";

$controller = app(\App\Http\Controllers\ReportController::class);
$row = $controller->getMonthlyReportData(new \Illuminate\Http\Request(['month' => '07', 'year' => '2026']))->getData(true)[0];
echo "report sch01_total={$row['total_salary_sch01']} salary_comp={$row['salary_component']} allow={$row['allowance_component']}\n";
echo "report budgetary={$row['budgetary_allowance']} br={$row['budget_relief_allowance']} salary_for_epf={$row['salary_for_epf']}\n";
