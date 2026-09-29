<?php

/*
 * Builds employee_import_template.xlsx (Employee Master bulk import).
 *   php deploy/import/make_employee_template.php [output.xlsx]
 */

require __DIR__.'/../../vendor/autoload.php';

ini_set('memory_limit', '512M');

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const DATA_FIRST_ROW = 4;
const DATA_LAST_ROW = 2003;

$spec = require __DIR__.'/employee_import_columns.php';
$out = $argv[1] ?? __DIR__.'/employee_import_template.xlsx';

$book = new Spreadsheet();
$book->getProperties()->setTitle('Sohan HR - Employee import')->setCreator('Sohan HR');

// Instructions ---------------------------------------------------------------
$help = $book->getActiveSheet();
$help->setTitle('Instructions');
$lines = [
    ['Sohan HR - Employee bulk import', 'title'],
    [''],
    ['How to use', 'h'],
    ['1. Fill the "Employees" sheet: one row per employee, starting on row 4. Rows 1-3 are headers - do not delete them.'],
    ['2. Red headers are required. Grey headers are optional. Row 3 under each header shows the format.'],
    ['3. Children, Qualifications and Following Qualifications are optional. Link each row to an employee with the same Attendance No.'],
    ['4. Education is optional - leave those sheets empty if an employee has no qualifications.'],
    ['5. Save the file, then run:   php deploy/import/employee_excel_to_sql.php  your_file.xlsx'],
    ['6. Fix any errors it lists and run again. When it succeeds it writes a .sql file next to your Excel file.'],
    ['7. Open phpMyAdmin, select the tenant database (e.g. bdc live DB) and import / run that .sql file.'],
    ['8. The last results in phpMyAdmin list how many employees were imported and any rows that were skipped (with the reason).'],
    [''],
    ['Rules (same as Employee Master)', 'h'],
    ['- Company Code must already exist (Cybernetic Admin / Company Master).'],
    ['- Department and Location are required. Missing departments, sub-departments, locations, designations and employment types'],
    ['  are created automatically for that company (use --no-create-masters to reject them instead).'],
    ['- NIC: 9 digits + V/X (e.g. 901234567V) or 12 digits (e.g. 199012345678).'],
    ['- Dates: YYYY-MM-DD (e.g. 2024-01-15). Excel date cells are also accepted.'],
    ['- Attendance No, EPF No, NIC, Email and Mobile must be unique. Employees that already exist in the database are skipped, not overwritten.'],
    ['- Spouse: leave all spouse columns empty, or fill Relationship, Title, Name, NIC and Date of Birth.'],
    ['- Yes/No columns: empty uses the default shown in row 3.'],
    ['- A portal login is created for each employee (email = Email column, first password = NIC), same as Employee Master.'],
    ['- Qualifications appear in Employee Master only when the Qualifications add-on is enabled for the company in Cybernetic Admin.'],
];
foreach ($lines as $i => $line) {
    $cell = 'A'.($i + 1);
    $help->setCellValue($cell, $line[0]);
    $style = $line[1] ?? null;
    if ($style === 'title') {
        $help->getStyle($cell)->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0B4F5C');
    } elseif ($style === 'h') {
        $help->getStyle($cell)->getFont()->setBold(true)->setSize(12);
    }
}
$help->getColumnDimension('A')->setWidth(140);

// Lists (dropdown sources) ---------------------------------------------------
$lists = $book->createSheet();
$lists->setTitle('Lists');
$col = 1;
foreach ($spec['lists'] as $name => $values) {
    $letter = Coordinate::stringFromColumnIndex($col);
    $lists->setCellValue($letter.'1', $name);
    $lists->getStyle($letter.'1')->getFont()->setBold(true);
    foreach ($values as $r => $value) {
        $lists->setCellValueExplicit($letter.($r + 2), $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $range = '$'.$letter.'$2:$'.$letter.'$'.(count($values) + 1);
    $book->addNamedRange(new NamedRange('list_'.$name, $lists, $range));
    $lists->getColumnDimension($letter)->setAutoSize(true);
    $col++;
}

// Data sheets ------------------------------------------------------------------
$hint = function (array $c): string {
    [, , $required, $type, $opt] = $c;
    $req = $required ? 'Required' : 'Optional';
    return match ($type) {
        'date' => "$req · YYYY-MM-DD",
        'nic' => "$req · 901234567V / 199012345678",
        'email' => "$req · name@example.com",
        'decimal' => "$req · number",
        'year' => "$req · e.g. 2020",
        'month' => "$req · 1-12",
        'yesno' => "Yes / No (default $opt)",
        'list' => "$req · pick from list",
        default => "$req · max $opt",
    };
};

$thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]]];

foreach ($spec['sheets'] as $sheetName => $sheetSpec) {
    $sheet = $book->createSheet();
    $sheet->setTitle($sheetName);
    $col = 1;
    foreach ($sheetSpec['sections'] as $section => $sec) {
        $start = $col;
        foreach ($sec['columns'] as $c) {
            [$key, $header, $required, $type, $opt] = $c;
            $letter = Coordinate::stringFromColumnIndex($col);

            $sheet->setCellValue($letter.'2', $header.($required ? ' *' : ''));
            $sheet->setCellValue($letter.'3', $hint($c));
            $sheet->getStyle($letter.'2')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $required ? 'FFFFFF' : '1F2937']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $required ? 'DC2626' : 'E5E7EB']],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle($letter.'3')->applyFromArray([
                'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '6B7280']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $sec['color']]],
            ]);

            $range = $letter.DATA_FIRST_ROW.':'.$letter.DATA_LAST_ROW;
            $format = match ($type) {
                'date' => 'yyyy-mm-dd',
                'decimal' => '#,##0.00',
                'year', 'month' => '0',
                default => NumberFormat::FORMAT_TEXT,
            };
            $sheet->getStyle("$letter:$letter")->getNumberFormat()->setFormatCode($format);

            $listName = $type === 'yesno' ? 'YesNo' : ($type === 'list' ? $opt[0] : null);
            if ($listName) {
                $strict = $type === 'yesno' || $opt[1];
                $dv = new DataValidation();
                $dv->setType(DataValidation::TYPE_LIST)
                    ->setAllowBlank(true)
                    ->setShowDropDown(true)
                    ->setShowErrorMessage(true)
                    ->setErrorStyle($strict ? DataValidation::STYLE_STOP : DataValidation::STYLE_INFORMATION)
                    ->setErrorTitle($header)
                    ->setError($strict ? 'Pick a value from the list.' : 'Not in the usual list - it will be created as new.')
                    ->setFormula1('list_'.$listName);
                $sheet->setDataValidation($range, $dv);
            }

            $width = match ($type) {
                'date' => 14, 'yesno', 'year', 'month' => 12, 'decimal' => 14, 'nic' => 16,
                default => ($opt !== null && !is_array($opt) && $opt >= 191) ? 30 : 20,
            };
            $sheet->getColumnDimension($letter)->setWidth(max($width, min(40, strlen($header) + 4)));
            $col++;
        }
        $from = Coordinate::stringFromColumnIndex($start);
        $to = Coordinate::stringFromColumnIndex($col - 1);
        $sheet->mergeCells("{$from}1:{$to}1");
        $sheet->setCellValue("{$from}1", $section);
        $sheet->getStyle("{$from}1:{$to}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '111827']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $sec['color']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }
    $last = Coordinate::stringFromColumnIndex($col - 1);
    $sheet->getStyle("A1:{$last}3")->applyFromArray($thin);
    $sheet->getRowDimension(2)->setRowHeight(32);
    $sheet->freezePane($sheetName === 'Employees' ? 'C'.DATA_FIRST_ROW : 'B'.DATA_FIRST_ROW);
}

$book->setActiveSheetIndex(0);
$lists->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
(new Xlsx($book))->save($out);
echo "Template written: $out\n";
