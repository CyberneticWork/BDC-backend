<?php

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'C:/Users/imesh/Downloads/Company & Employee Personal Details.xlsx';
$wb = IOFactory::load($path);
$ws = $wb->getSheetByName('Employee Master');
$rows = $ws->toArray(null, true, true, true);

$headerRow = $rows[3] ?? [];
$employeeCols = [];
foreach ($headerRow as $col => $name) {
    if ($col === 'A' || $col === 'B') continue;
    $name = trim((string)$name);
    if ($name !== '') $employeeCols[$col] = $name;
}

$get = fn($rowNum, $col) => trim((string)($rows[$rowNum][$col] ?? ''));

foreach ($employeeCols as $col => $displayName) {
    $issues = [];
    if ($get(11, $col) === '') $issues[] = 'missing NIC';
    if ($get(55, $col) === '') $issues[] = 'missing email';
    if ($get(9, $col) === '') $issues[] = 'missing attendance no';
    if ($get(10, $col) === '') $issues[] = 'missing EPF';
    if ($get(76, $col) === '') $issues[] = 'missing basic salary';
    if ($get(93, $col) === '') $issues[] = 'missing company id';

    echo "$col $displayName";
    if ($issues) echo ' [' . implode(', ', $issues) . ']';
    echo "\n";
    echo "  emp={$get(19,$col)} dept={$get(94,$col)} desig={$get(98,$col)}\n";
}
