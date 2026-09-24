<?php

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = dirname(__DIR__) . '/deploy/templates/employee_master_import.xlsx';
app(App\Services\EmployeeExcelTemplateService::class)->saveTo($path);
echo 'wrote ' . filesize($path) . " bytes\n";