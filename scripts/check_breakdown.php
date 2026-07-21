<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = \App\Models\salary_process::where('year', '2026')->whereIn('month', ['07', 7])->first();
$b = is_string($s->salary_breakdown) ? json_decode($s->salary_breakdown, true) : $s->salary_breakdown;
echo json_encode($b, JSON_PRETTY_PRINT);
