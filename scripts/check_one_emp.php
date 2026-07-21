<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = \App\Models\salary_process::with('employee.compensation')
    ->where('year', '2026')->whereIn('month', ['07', 7])
    ->where('full_name', 'like', '%Sadeepa%')->first();

echo json_encode([
    'name' => $s->full_name,
    'allowances' => json_decode($s->allowances, true),
    'bonuses' => json_decode($s->bonuses, true),
    'comp_monthly_bonus' => $s->employee->compensation->monthly_bonus ?? null,
], JSON_PRETTY_PRINT);
