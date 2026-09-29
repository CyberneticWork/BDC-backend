<?php

/*
 * Converts a filled employee_import_template.xlsx into one SQL file for phpMyAdmin.
 *
 *   php deploy/import/employee_excel_to_sql.php filled.xlsx [options]
 *
 *   --out=file.sql          output path (default: next to the Excel file)
 *   --company=CODE          company code for rows where Company Code is empty
 *   --no-create-masters     reject unknown departments / locations / designations instead of creating them
 *   --skip-invalid          write SQL for the valid rows even when some rows have errors
 */

require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

const HEADER_ROW = 2;
const DATA_FIRST_ROW = 4;

$opts = ['out' => null, 'company' => null, 'create' => true, 'skip' => false];
$file = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $opts['out'] = substr($arg, 6);
    } elseif (str_starts_with($arg, '--company=')) {
        $opts['company'] = strtoupper(trim(substr($arg, 10)));
    } elseif ($arg === '--no-create-masters') {
        $opts['create'] = false;
    } elseif ($arg === '--skip-invalid') {
        $opts['skip'] = true;
    } elseif (!str_starts_with($arg, '--')) {
        $file = $arg;
    }
}
if (!$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php deploy/import/employee_excel_to_sql.php filled.xlsx [--out=x.sql] [--company=CODE] [--no-create-masters] [--skip-invalid]\n");
    exit(2);
}

$spec = require __DIR__.'/employee_import_columns.php';
$errors = [];
$warnings = [];

function norm_header(string $h): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', str_replace('*', '', $h))));
}

function cell_raw($sheet, int $col, int $row)
{
    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row);
    $v = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
    if ($v instanceof RichText) {
        $v = $v->getPlainText();
    }
    return $v;
}

function as_text($v): string
{
    if ($v === null) {
        return '';
    }
    if (is_float($v) && floor($v) == $v && abs($v) < 1e15) {
        return sprintf('%.0f', $v);
    }
    if (is_bool($v)) {
        return $v ? 'Yes' : 'No';
    }
    return trim(preg_replace('/\s+/u', ' ', (string) $v));
}

function as_date($v): ?string
{
    if (is_int($v) || is_float($v)) {
        return ExcelDate::excelToDateTimeObject($v)->format('Y-m-d');
    }
    $s = as_text($v);
    foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d H:i:s'] as $fmt) {
        $d = DateTime::createFromFormat('!'.$fmt, $s);
        if ($d && $d->format($fmt) === $s) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

function nic_error(string $nic): ?string
{
    if (!preg_match('/^[0-9]{9}[VX]$/', $nic) && !preg_match('/^[0-9]{12}$/', $nic)) {
        return 'is not a valid Sri Lankan NIC (901234567V or 199012345678)';
    }
    if (strlen($nic) === 12) {
        $year = (int) substr($nic, 0, 4);
        if ($year < 1900 || $year > (int) date('Y')) {
            return 'has an invalid year';
        }
    }
    return null;
}

/** Reads one sheet into rows of normalized values; records errors per cell. */
function read_sheet($book, string $name, array $sheetSpec, array $lists, array &$errors): array
{
    $sheet = $book->getSheetByName($name);
    $columns = [];
    foreach ($sheetSpec['sections'] as $sec) {
        foreach ($sec['columns'] as $c) {
            $columns[] = $c;
        }
    }
    if (!$sheet) {
        if ($name === 'Employees') {
            $errors[] = 'Sheet "Employees" is missing - use the template.';
        }
        return [];
    }

    $headerMap = [];
    $lastCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    for ($col = 1; $col <= $lastCol; $col++) {
        $h = norm_header(as_text(cell_raw($sheet, $col, HEADER_ROW)));
        if ($h !== '') {
            $headerMap[$h] = $col;
        }
    }
    $colOf = [];
    foreach ($columns as $c) {
        $found = $headerMap[norm_header($c[1])] ?? $headerMap[$c[0]] ?? null;
        if ($found) {
            $colOf[$c[0]] = $found;
        } elseif ($c[2]) {
            $errors[] = "$name: required column \"{$c[1]}\" is missing from row ".HEADER_ROW.'.';
        }
    }

    $rows = [];
    $lastRow = $sheet->getHighestDataRow();
    for ($r = DATA_FIRST_ROW; $r <= $lastRow; $r++) {
        $raw = [];
        $empty = true;
        foreach ($columns as $c) {
            $v = isset($colOf[$c[0]]) ? cell_raw($sheet, $colOf[$c[0]], $r) : null;
            if (as_text($v) !== '') {
                $empty = false;
            }
            $raw[$c[0]] = $v;
        }
        if ($empty) {
            continue;
        }

        $row = ['_row' => $r, '_errors' => []];
        foreach ($columns as $c) {
            [$key, $header, $required, $type, $opt] = $c;
            $v = $raw[$key];
            $text = as_text($v);
            $err = function (string $msg) use (&$row, $header) {
                $row['_errors'][] = "$header $msg";
            };

            if ($text === '') {
                if ($type === 'yesno') {
                    $row[$key] = $opt === 'Yes' ? 1 : 0;
                    continue;
                }
                if ($required) {
                    $err('is required');
                }
                $row[$key] = null;
                continue;
            }

            switch ($type) {
                case 'date':
                    $d = as_date($v);
                    if ($d === null) {
                        $err("\"$text\" is not a date (use YYYY-MM-DD)");
                    }
                    $row[$key] = $d;
                    break;
                case 'nic':
                    $nic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $text));
                    if ($e = nic_error($nic)) {
                        $err("\"$text\" $e");
                    }
                    $row[$key] = $nic;
                    break;
                case 'email':
                    $email = strtolower($text);
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $err("\"$text\" is not a valid email");
                    }
                    $row[$key] = $email;
                    break;
                case 'decimal':
                    $num = str_replace([',', ' '], '', $text);
                    if (!is_numeric($num)) {
                        $err("\"$text\" is not a number");
                        $num = null;
                    }
                    $row[$key] = $num === null ? null : round((float) $num, 2);
                    break;
                case 'year':
                case 'month':
                    [$min, $max] = $type === 'year' ? [1900, 2100] : [1, 12];
                    if (!ctype_digit($text) || (int) $text < $min || (int) $text > $max) {
                        $err("\"$text\" must be a whole number $min-$max");
                    }
                    $row[$key] = ctype_digit($text) ? (int) $text : null;
                    break;
                case 'yesno':
                    $b = strtolower($text);
                    if (in_array($b, ['yes', 'y', 'true', '1'], true)) {
                        $row[$key] = 1;
                    } elseif (in_array($b, ['no', 'n', 'false', '0'], true)) {
                        $row[$key] = 0;
                    } else {
                        $err("\"$text\" must be Yes or No");
                        $row[$key] = 0;
                    }
                    break;
                case 'list':
                    [$listName, $strict] = $opt;
                    $match = null;
                    foreach ($lists[$listName] as $allowed) {
                        if (strcasecmp($allowed, $text) === 0) {
                            $match = $allowed;
                            break;
                        }
                    }
                    if ($match === null && $strict) {
                        $err("\"$text\" is not one of: ".implode(', ', $lists[$listName]));
                    }
                    $row[$key] = $match ?? $text;
                    break;
                default:
                    if (mb_strlen($text) > $opt) {
                        $err("is longer than $opt characters");
                    }
                    $row[$key] = $text;
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

// ---------------------------------------------------------------------------
$reader = IOFactory::createReaderForFile($file);
$reader->setReadDataOnly(false);
$book = $reader->load($file);

$emps = read_sheet($book, 'Employees', $spec['sheets']['Employees'], $spec['lists'], $errors);
$children = read_sheet($book, 'Children', $spec['sheets']['Children'], $spec['lists'], $errors);
$quals = read_sheet($book, 'Qualifications', $spec['sheets']['Qualifications'], $spec['lists'], $errors);
$fquals = read_sheet($book, 'Following Qualifications', $spec['sheets']['Following Qualifications'], $spec['lists'], $errors);

// Employee-level rules -------------------------------------------------------
$seen = ['attendance_no' => [], 'epf_no' => [], 'nic' => [], 'email' => [], 'mobile' => [], 'spouse_nic' => []];
$labels = ['attendance_no' => 'Attendance No', 'epf_no' => 'EPF No', 'nic' => 'NIC', 'email' => 'Email', 'mobile' => 'Mobile', 'spouse_nic' => 'Spouse NIC'];
$today = date('Y-m-d');
foreach ($emps as &$e) {
    if (($e['company_code'] ?? null) === null && $opts['company']) {
        $e['company_code'] = $opts['company'];
        $e['_errors'] = array_values(array_filter($e['_errors'], fn ($m) => !str_starts_with($m, 'Company Code ')));
    }
    if ($e['company_code'] !== null) {
        $e['company_code'] = strtoupper($e['company_code']);
    }
    foreach ($seen as $key => $_) {
        $val = $e[$key] ?? null;
        if ($val === null || $val === '') {
            continue;
        }
        $k = strtolower((string) $val);
        if (isset($seen[$key][$k])) {
            $e['_errors'][] = "{$labels[$key]} \"$val\" is also used on row {$seen[$key][$k]}";
        } else {
            $seen[$key][$k] = $e['_row'];
        }
    }
    if ($e['dob'] && $e['dob'] > $today) {
        $e['_errors'][] = 'Date of Birth is in the future';
    }
    if ($e['contract_from'] && $e['contract_to'] && $e['contract_to'] < $e['contract_from']) {
        $e['_errors'][] = 'Contract To must be on or after Contract From';
    }
    $spouseKeys = ['spouse_type' => 'Spouse Relationship', 'spouse_title' => 'Spouse Title', 'spouse_name' => 'Spouse Name', 'spouse_nic' => 'Spouse NIC', 'spouse_dob' => 'Spouse Date of Birth'];
    $spouseFilled = array_filter(array_keys($spouseKeys), fn ($k) => $e[$k] !== null);
    if ($spouseFilled && count($spouseFilled) < count($spouseKeys)) {
        foreach ($spouseKeys as $k => $label) {
            if ($e[$k] === null) {
                $e['_errors'][] = "$label is required when spouse details are given";
            }
        }
    }
    if ($e['spouse_nic'] && $e['nic'] && $e['spouse_nic'] === $e['nic']) {
        $e['_errors'][] = 'Spouse NIC cannot be the same as employee NIC';
    }
}
unset($e);

$validAttendance = [];
foreach ($emps as $e) {
    if (!$e['_errors'] && $e['attendance_no'] !== null) {
        $validAttendance[strtolower($e['attendance_no'])] = true;
    }
}
$allAttendance = array_change_key_case(array_flip(array_filter(array_column($emps, 'attendance_no'))), CASE_LOWER);

$linkRows = function (array &$rows, string $sheet) use ($allAttendance) {
    foreach ($rows as &$r) {
        if ($r['attendance_no'] !== null && !isset($allAttendance[strtolower($r['attendance_no'])])) {
            $r['_errors'][] = "Attendance No \"{$r['attendance_no']}\" is not on the Employees sheet";
        }
    }
};
$linkRows($children, 'Children');
$linkRows($quals, 'Qualifications');
$linkRows($fquals, 'Following Qualifications');

$childNics = [];
foreach ($children as &$c) {
    if ($c['dob'] && $c['dob'] > $today) {
        $c['_errors'][] = 'Child Date of Birth is in the future';
    }
    if ($c['nic']) {
        $k = strtoupper($c['nic']);
        if (isset($childNics[$k])) {
            $c['_errors'][] = "Child NIC \"{$c['nic']}\" is also used on row {$childNics[$k]}";
        }
        $childNics[$k] = $c['_row'];
    }
}
unset($c);
$quals = array_values(array_filter($quals, fn ($q) => $q['qualification_type'] || $q['course_name'] || $q['institute_name'] || $q['completion_year']));
$fquals = array_values(array_filter($fquals, fn ($q) => $q['qualification_name'] || $q['institute_name'] || $q['start_year'] || $q['end_year']));

// Report ---------------------------------------------------------------------
$collect = function (array $rows, string $sheet) use (&$errors) {
    foreach ($rows as $r) {
        foreach ($r['_errors'] as $m) {
            $errors[] = "$sheet row {$r['_row']}: $m";
        }
    }
};
$collect($emps, 'Employees');
$collect($children, 'Children');
$collect($quals, 'Qualifications');
$collect($fquals, 'Following Qualifications');

if (!$emps) {
    $errors[] = 'Employees sheet has no data rows (data starts on row '.DATA_FIRST_ROW.').';
}
if ($errors) {
    fwrite(STDERR, count($errors)." problem(s) found:\n  - ".implode("\n  - ", $errors)."\n");
    if (!$opts['skip'] || !$emps) {
        fwrite(STDERR, "\nNo SQL written. Fix the Excel file and run again".($emps ? ' (or add --skip-invalid to import only the valid rows).' : '.')."\n");
        exit(1);
    }
}

$keep = fn (array $rows, bool $needsEmployee) => array_values(array_filter($rows, function ($r) use ($needsEmployee, $validAttendance) {
    return !$r['_errors'] && (!$needsEmployee || isset($validAttendance[strtolower((string) $r['attendance_no'])]));
}));
$emps = $keep($emps, false);
$children = $keep($children, true);
$quals = $keep($quals, true);
$fquals = $keep($fquals, true);
if (!$emps) {
    fwrite(STDERR, "No valid employee rows left - nothing to import.\n");
    exit(1);
}

// Same master name typed with different case/spacing => one master row.
$canon = [];
$canonical = function (string $scope, ?string $value) use (&$canon): ?string {
    if ($value === null) {
        return null;
    }
    $k = $scope.'|'.mb_strtolower($value);
    return $canon[$k] ??= $value;
};
foreach ($emps as &$e) {
    $e['department'] = $canonical('dept|'.$e['company_code'], $e['department']);
    $e['sub_department'] = $canonical('sub|'.$e['company_code'].'|'.mb_strtolower($e['department']), $e['sub_department']);
    $e['location'] = $canonical('loc|'.$e['company_code'], $e['location']);
    $e['designation'] = $canonical('desig', $e['designation']);
    $e['employment_type'] = $canonical('etype', $e['employment_type']);
    $e['pw_hash'] = password_hash($e['nic'], PASSWORD_BCRYPT, ['cost' => 10]);
}
unset($e);

// SQL --------------------------------------------------------------------------
function q($v): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string) $v;
    }
    return "'".str_replace(['\\', "'", "\0", "\r", "\n"], ['\\\\', "''", '', '\\r', '\\n'], (string) $v)."'";
}

$empCols = [];
foreach ($spec['sheets']['Employees']['sections'] as $sec) {
    foreach ($sec['columns'] as $c) {
        $empCols[$c[0]] = $c[3];
    }
}
$ddlType = fn (string $type) => match ($type) {
    'date' => 'DATE NULL',
    'decimal' => 'DECIMAL(12,2) NULL',
    'yesno' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'year', 'month' => 'SMALLINT NULL',
    default => 'VARCHAR(255) NULL',
};

$sql = [];
$add = function (string $s) use (&$sql) {
    $sql[] = $s;
};
$insertRows = function (string $table, array $cols, array $rows) use ($add) {
    foreach (array_chunk($rows, 200) as $chunk) {
        $values = array_map(fn ($r) => '('.implode(', ', array_map(fn ($c) => q($r[$c] ?? null), $cols)).')', $chunk);
        $add("INSERT INTO `$table` (`".implode('`, `', $cols)."`) VALUES\n".implode(",\n", $values).';');
    }
};

$token = bin2hex(random_bytes(4));
$marker = "__IMP_{$token}_";
$source = basename($file);
$generated = date('Y-m-d H:i');
[$e_count, $c_count, $q_count, $f_count] = [count($emps), count($children), count($quals), count($fquals)];
$createFlag = $opts['create'] ? 1 : 0;
$add(<<<SQL
-- =============================================================================
-- Sohan HR employee import - generated $generated from $source
-- Employees: $e_count, Children: $c_count, Qualifications: $q_count, Following: $f_count
--
-- Run in phpMyAdmin on the tenant database (select it first), whole file at once.
-- Existing employees (same Attendance No / EPF / NIC / Mobile) are skipped, never overwritten.
-- The last result lists every Excel row with "Imported" or the reason it was skipped.
-- Contains NIC-based password hashes: delete this file after importing.
-- =============================================================================

SET NAMES utf8mb4;
SET @create_masters := $createFlag;
SET @cs := IFNULL((SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'nic'), 'utf8mb4');
SET @co := IFNULL((SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'nic'), 'utf8mb4_unicode_ci');
SET @tail := CONCAT(' ENGINE=InnoDB DEFAULT CHARSET=', @cs, ' COLLATE=', @co);

-- 1) Schema needed by the current Employee Master (no-op when present) -------
SET @s := CONCAT('CREATE TABLE IF NOT EXISTS `company_locations` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `company_id` BIGINT UNSIGNED NOT NULL, `name` VARCHAR(191) NOT NULL, `address` VARCHAR(255) NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL, KEY `company_locations_company_id_index` (`company_id`))', @tail);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_assignments' AND COLUMN_NAME = 'location_id') = 0,
  'ALTER TABLE `organization_assignments` ADD COLUMN `location_id` BIGINT UNSIGNED NULL, ADD KEY `organization_assignments_location_id_index` (`location_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SQL);

if ($quals) {
    $add(<<<'SQL'
CREATE TABLE IF NOT EXISTS `employee_qualifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `employee_id` bigint unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'completed',
  `qualification_type` varchar(80) DEFAULT NULL,
  `course_name` varchar(191) DEFAULT NULL,
  `institute_name` varchar(191) DEFAULT NULL,
  `completion_year` smallint unsigned DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  KEY `employee_qualifications_employee_id_index` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `employee_qualifications`
  MODIFY `qualification_type` varchar(80) NULL DEFAULT NULL,
  MODIFY `institute_name` varchar(191) NULL DEFAULT NULL;
SQL);
}
if ($fquals) {
    $add(<<<'SQL'
CREATE TABLE IF NOT EXISTS `employee_following_qualifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `employee_id` bigint unsigned NOT NULL,
  `qualification_name` varchar(191) DEFAULT NULL,
  `institute_name` varchar(191) DEFAULT NULL,
  `start_year` smallint unsigned DEFAULT NULL,
  `start_month` tinyint unsigned DEFAULT NULL,
  `end_year` smallint unsigned DEFAULT NULL,
  `end_month` tinyint unsigned DEFAULT NULL,
  `lecture_type` varchar(10) DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  KEY `employee_following_qualifications_employee_id_index` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `employee_following_qualifications`
  MODIFY `qualification_name` varchar(191) NULL DEFAULT NULL,
  MODIFY `institute_name` varchar(191) NULL DEFAULT NULL,
  MODIFY `start_year` smallint unsigned NULL DEFAULT NULL,
  MODIFY `start_month` tinyint unsigned NULL DEFAULT NULL,
  MODIFY `lecture_type` varchar(10) NULL DEFAULT NULL;
SQL);
}

// Staging tables (created with the same collation as `employees` so name joins work).
$empDdl = ['`row_no` INT NOT NULL PRIMARY KEY'];
foreach ($empCols as $col => $type) {
    $empDdl[] = "`$col` ".$ddlType($type);
}
foreach (['company_id', 'department_id', 'sub_department_id', 'location_id', 'designation_id', 'employment_type_id', 'spouse_id', 'oa_id', 'employee_id'] as $idCol) {
    $empDdl[] = "`$idCol` BIGINT UNSIGNED NULL";
}
$empDdl[] = '`pw_hash` VARCHAR(100) NULL';
$empDdl[] = '`spouse_ok` TINYINT(1) NOT NULL DEFAULT 0';
$empDdl[] = '`skip_reason` VARCHAR(255) NULL';
$staging = [
    '_imp_emp' => $empDdl,
    '_imp_child' => ['`row_no` INT NOT NULL PRIMARY KEY', '`attendance_no` VARCHAR(255) NULL', '`name` VARCHAR(255) NULL', '`dob` DATE NULL', '`nic` VARCHAR(255) NULL'],
    '_imp_qual' => ['`row_no` INT NOT NULL PRIMARY KEY', '`attendance_no` VARCHAR(255) NULL', '`qualification_type` VARCHAR(255) NULL', '`course_name` VARCHAR(255) NULL', '`institute_name` VARCHAR(255) NULL', '`completion_year` SMALLINT NULL', '`sort_order` SMALLINT NOT NULL DEFAULT 0'],
    '_imp_fqual' => ['`row_no` INT NOT NULL PRIMARY KEY', '`attendance_no` VARCHAR(255) NULL', '`qualification_name` VARCHAR(255) NULL', '`institute_name` VARCHAR(255) NULL', '`start_year` SMALLINT NULL', '`start_month` SMALLINT NULL', '`end_year` SMALLINT NULL', '`end_month` SMALLINT NULL', '`lecture_type` VARCHAR(255) NULL', '`sort_order` SMALLINT NOT NULL DEFAULT 0'],
];
$add("\n-- 2) Staging tables --------------------------------------------------------");
foreach ($staging as $table => $ddl) {
    $add("DROP TABLE IF EXISTS `$table`;");
    $add("SET @s := CONCAT('CREATE TABLE `$table` (".implode(', ', $ddl).")', @tail);\nPREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;");
}

$add("\nSTART TRANSACTION;");
$empRows = array_map(fn ($e) => ['row_no' => $e['_row']] + array_intersect_key($e, $empCols) + ['pw_hash' => $e['pw_hash']], $emps);
$insertRows('_imp_emp', array_merge(['row_no'], array_keys($empCols), ['pw_hash']), $empRows);
$order = [];
$seq = function (array $rows) use (&$order) {
    $order = [];
    return array_map(function ($r) use (&$order) {
        $k = strtolower($r['attendance_no']);
        $order[$k] = ($order[$k] ?? -1) + 1;
        return $r + ['row_no' => $r['_row'], 'sort_order' => $order[$k]];
    }, $rows);
};
if ($children) {
    $insertRows('_imp_child', ['row_no', 'attendance_no', 'name', 'dob', 'nic'], array_map(fn ($r) => $r + ['row_no' => $r['_row']], $children));
}
if ($quals) {
    $insertRows('_imp_qual', ['row_no', 'attendance_no', 'qualification_type', 'course_name', 'institute_name', 'completion_year', 'sort_order'], $seq($quals));
}
if ($fquals) {
    $insertRows('_imp_fqual', ['row_no', 'attendance_no', 'qualification_name', 'institute_name', 'start_year', 'start_month', 'end_year', 'end_month', 'lecture_type', 'sort_order'], $seq($fquals));
}

$add(<<<SQL

-- 3) Company + masters (missing masters created when @create_masters = 1) ----
UPDATE `_imp_emp` i JOIN `companies` c ON UPPER(TRIM(c.company_code)) = i.company_code AND c.deleted_at IS NULL
SET i.company_id = c.id;

UPDATE `employment_types` t JOIN (SELECT DISTINCT employment_type FROM `_imp_emp`) i ON t.name = i.employment_type
SET t.deleted_at = NULL WHERE t.deleted_at IS NOT NULL AND @create_masters = 1;
INSERT INTO `employment_types` (name, created_at, updated_at)
SELECT DISTINCT i.employment_type, NOW(), NOW() FROM `_imp_emp` i
WHERE @create_masters = 1 AND NOT EXISTS (SELECT 1 FROM `employment_types` t WHERE t.name = i.employment_type);
UPDATE `_imp_emp` i JOIN `employment_types` t ON t.name = i.employment_type AND t.deleted_at IS NULL
SET i.employment_type_id = t.id;

UPDATE `designations` d JOIN (SELECT DISTINCT designation FROM `_imp_emp`) i ON d.name = i.designation
SET d.deleted_at = NULL WHERE d.deleted_at IS NOT NULL AND @create_masters = 1;
INSERT INTO `designations` (name, description, created_at, updated_at)
SELECT DISTINCT i.designation, 'Created by employee import', NOW(), NOW() FROM `_imp_emp` i
WHERE @create_masters = 1 AND NOT EXISTS (SELECT 1 FROM `designations` d WHERE d.name = i.designation);
UPDATE `_imp_emp` i JOIN `designations` d ON d.name = i.designation AND d.deleted_at IS NULL
SET i.designation_id = d.id;

INSERT INTO `departments` (company_id, name, created_at, updated_at)
SELECT DISTINCT i.company_id, i.department, NOW(), NOW() FROM `_imp_emp` i
WHERE @create_masters = 1 AND i.company_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `departments` d WHERE d.company_id = i.company_id AND d.name = i.department AND d.deleted_at IS NULL);
UPDATE `_imp_emp` i JOIN `departments` d ON d.company_id = i.company_id AND d.name = i.department AND d.deleted_at IS NULL
SET i.department_id = d.id;

INSERT INTO `sub_departments` (name, department_id, created_at, updated_at)
SELECT DISTINCT i.sub_department, i.department_id, NOW(), NOW() FROM `_imp_emp` i
WHERE @create_masters = 1 AND i.department_id IS NOT NULL AND i.sub_department IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `sub_departments` s WHERE s.department_id = i.department_id AND s.name = i.sub_department AND s.deleted_at IS NULL);
UPDATE `_imp_emp` i JOIN `sub_departments` s ON s.department_id = i.department_id AND s.name = i.sub_department AND s.deleted_at IS NULL
SET i.sub_department_id = s.id;

INSERT INTO `company_locations` (company_id, name, created_at, updated_at)
SELECT DISTINCT i.company_id, i.location, NOW(), NOW() FROM `_imp_emp` i
WHERE @create_masters = 1 AND i.company_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `company_locations` l WHERE l.company_id = i.company_id AND l.name = i.location);
UPDATE `_imp_emp` i JOIN `company_locations` l ON l.company_id = i.company_id AND l.name = i.location
SET i.location_id = l.id;

-- 4) Rows that cannot be imported ------------------------------------------
UPDATE `_imp_emp` SET skip_reason = CONCAT('Company code ', company_code, ' not found') WHERE skip_reason IS NULL AND company_id IS NULL;
UPDATE `_imp_emp` SET skip_reason = CONCAT('Department "', department, '" not found for this company') WHERE skip_reason IS NULL AND department_id IS NULL;
UPDATE `_imp_emp` SET skip_reason = CONCAT('Sub Department "', sub_department, '" not found in the department') WHERE skip_reason IS NULL AND sub_department IS NOT NULL AND sub_department_id IS NULL;
UPDATE `_imp_emp` SET skip_reason = CONCAT('Location "', location, '" not found for this company') WHERE skip_reason IS NULL AND location_id IS NULL;
UPDATE `_imp_emp` SET skip_reason = CONCAT('Designation "', designation, '" not found') WHERE skip_reason IS NULL AND designation_id IS NULL;
UPDATE `_imp_emp` SET skip_reason = CONCAT('Employment type "', employment_type, '" not found') WHERE skip_reason IS NULL AND employment_type_id IS NULL;
UPDATE `_imp_emp` i SET i.skip_reason = 'Attendance No already exists'
WHERE i.skip_reason IS NULL AND EXISTS (SELECT 1 FROM `employees` e WHERE e.attendance_employee_no = i.attendance_no);
UPDATE `_imp_emp` i SET i.skip_reason = 'EPF No already exists'
WHERE i.skip_reason IS NULL AND EXISTS (SELECT 1 FROM `employees` e WHERE e.epf = i.epf_no);
UPDATE `_imp_emp` i SET i.skip_reason = 'NIC already exists'
WHERE i.skip_reason IS NULL AND EXISTS (SELECT 1 FROM `employees` e WHERE e.nic = i.nic);
UPDATE `_imp_emp` i SET i.skip_reason = 'Mobile already used by another employee'
WHERE i.skip_reason IS NULL AND EXISTS (SELECT 1 FROM `contact_details` c WHERE c.mobile_line = i.mobile);

-- 5) Spouse -------------------------------------------------------------------
UPDATE `_imp_emp` i SET i.spouse_ok = 1
WHERE i.skip_reason IS NULL AND i.spouse_nic IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `spouses` s WHERE s.nic = i.spouse_nic);
INSERT INTO `spouses` (type, title, name, age, dob, nic, created_at, updated_at)
SELECT spouse_type, spouse_title, spouse_name, TIMESTAMPDIFF(YEAR, spouse_dob, CURDATE()), spouse_dob, spouse_nic, NOW(), NOW()
FROM `_imp_emp` WHERE spouse_ok = 1;
UPDATE `_imp_emp` i JOIN `spouses` s ON s.nic = i.spouse_nic SET i.spouse_id = s.id WHERE i.spouse_ok = 1;

-- 6) Organization assignment -> employee --------------------------------------
INSERT INTO `organization_assignments` (
  company_id, department_id, sub_department_id, designation_id, location_id, current_supervisor,
  date_of_joining, day_off, confirmation_date,
  probationary_period, training_period, contract_period,
  probationary_period_from, probationary_period_to, training_period_from, training_period_to,
  contract_period_from, contract_period_to, is_active, letter_path, created_at, updated_at
)
SELECT company_id, department_id, sub_department_id, designation_id, location_id, current_supervisor,
  date_joined, day_off, confirmation_date,
  probation, training, contract,
  probation_from, probation_to, training_from, training_to,
  contract_from, contract_to, active, CONCAT('$marker', row_no), NOW(), NOW()
FROM `_imp_emp` WHERE skip_reason IS NULL;
UPDATE `_imp_emp` i JOIN `organization_assignments` oa ON oa.letter_path = CONCAT('$marker', i.row_no)
SET i.oa_id = oa.id;
UPDATE `organization_assignments` SET letter_path = NULL WHERE LEFT(letter_path, CHAR_LENGTH('$marker')) = '$marker';

INSERT INTO `employees` (
  title, attendance_employee_no, epf, nic, dob, gender, religion, country_of_birth,
  name_with_initials, full_name, display_name, marital_status, is_active,
  employment_type_id, organization_assignment_id, spouse_id, email, created_at, updated_at
)
SELECT title, attendance_no, epf_no, nic, dob, LOWER(gender), religion, country_of_birth,
  name_with_initials, full_name, display_name, LOWER(marital_status), 1,
  employment_type_id, oa_id, spouse_id, email, NOW(), NOW()
FROM `_imp_emp` WHERE skip_reason IS NULL AND oa_id IS NOT NULL;
UPDATE `_imp_emp` i JOIN `employees` e ON e.attendance_employee_no = i.attendance_no
SET i.employee_id = e.id WHERE i.skip_reason IS NULL AND i.oa_id IS NOT NULL;

-- 7) Contact + compensation ------------------------------------------------------
INSERT INTO `contact_details` (
  employee_id, permanent_address, temporary_address, email, land_line, mobile_line,
  gn_division, police_station, district, province, electoral_division,
  emg_relationship, emg_name, emg_address, emg_tel, created_at, updated_at
)
SELECT employee_id, permanent_address, temporary_address, email, land_line, mobile,
  gn_division, police_station, district, province, electoral_division,
  emg_relationship, emg_name, emg_address, emg_tel, NOW(), NOW()
FROM `_imp_emp` WHERE skip_reason IS NULL AND employee_id IS NOT NULL;

INSERT INTO `compensation` (
  employee_id, employee_category, basic_salary, monthly_bonus, increment_value, increment_effected_date,
  bank_name, branch_name, bank_code, branch_code, bank_account_no, account_holder_name, comments,
  secondary_emp, primary_emp_basic, enable_epf_etf, ot_active, early_deduction, increment_active, active_nopay,
  ot_morning, ot_evening, ot_morning_rate, ot_night_rate, br1, br2, stamp,
  sports_fund_percentage, staff_fund_amount, created_at, updated_at
)
SELECT employee_id, employee_category, basic_salary, IFNULL(monthly_bonus, 0), increment_value, increment_effective_from,
  bank_name, branch_name, bank_code, branch_code, bank_account_no, account_holder_name, comments,
  secondary_emp, primary_emp_basic, enable_epf_etf, ot_active, early_deduction, increment_active, nopay_active,
  ot_morning, ot_evening, IFNULL(ot_morning_rate, 0), IFNULL(ot_night_rate, 0), br1, br2, stamp,
  sports_fund_percentage, IFNULL(staff_fund_amount, 0), NOW(), NOW()
FROM `_imp_emp` WHERE skip_reason IS NULL AND employee_id IS NOT NULL;

-- 8) Portal login (email, first password = NIC). Reuses an unlinked login with the same email/NIC.
UPDATE `users` u JOIN `_imp_emp` i ON u.email = i.email
SET u.employee_id = i.employee_id, u.deleted_at = NULL
WHERE i.skip_reason IS NULL AND i.employee_id IS NOT NULL
  AND (u.employee_id IS NULL OR NOT EXISTS (SELECT 1 FROM `employees` e WHERE e.id = u.employee_id AND e.deleted_at IS NULL));
UPDATE `users` u JOIN `_imp_emp` i ON u.nic = i.nic
SET u.employee_id = i.employee_id, u.deleted_at = NULL
WHERE i.skip_reason IS NULL AND i.employee_id IS NOT NULL
  AND (u.employee_id IS NULL OR NOT EXISTS (SELECT 1 FROM `employees` e WHERE e.id = u.employee_id AND e.deleted_at IS NULL));
INSERT INTO `users` (name, email, nic, employee_id, password, role, created_at, updated_at)
SELECT i.full_name, i.email, i.nic, i.employee_id, i.pw_hash, 'employee', NOW(), NOW()
FROM `_imp_emp` i
WHERE i.skip_reason IS NULL AND i.employee_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `users` u WHERE u.email = i.email OR u.nic = i.nic);

-- 9) Children + education (optional) ---------------------------------------------
INSERT INTO `childrens` (employee_id, name, age, dob, nic, created_at, updated_at)
SELECT e.employee_id, c.name, TIMESTAMPDIFF(YEAR, c.dob, CURDATE()), c.dob,
  CASE WHEN c.nic IS NULL OR EXISTS (SELECT 1 FROM `childrens` x WHERE x.nic = c.nic) THEN NULL ELSE c.nic END,
  NOW(), NOW()
FROM `_imp_child` c JOIN `_imp_emp` e ON e.attendance_no = c.attendance_no
WHERE e.skip_reason IS NULL AND e.employee_id IS NOT NULL;
SQL);

if ($quals) {
    $add(<<<'SQL'
INSERT INTO `employee_qualifications` (employee_id, status, qualification_type, course_name, institute_name, completion_year, sort_order, created_at, updated_at)
SELECT e.employee_id, 'completed', q.qualification_type, q.course_name, q.institute_name, q.completion_year, q.sort_order, NOW(), NOW()
FROM `_imp_qual` q JOIN `_imp_emp` e ON e.attendance_no = q.attendance_no
WHERE e.skip_reason IS NULL AND e.employee_id IS NOT NULL;
SQL);
}
if ($fquals) {
    $add(<<<'SQL'
INSERT INTO `employee_following_qualifications` (employee_id, qualification_name, institute_name, start_year, start_month, end_year, end_month, lecture_type, sort_order, created_at, updated_at)
SELECT e.employee_id, q.qualification_name, q.institute_name, q.start_year, q.start_month, q.end_year, q.end_month, q.lecture_type, q.sort_order, NOW(), NOW()
FROM `_imp_fqual` q JOIN `_imp_emp` e ON e.attendance_no = q.attendance_no
WHERE e.skip_reason IS NULL AND e.employee_id IS NOT NULL;
SQL);
}

$add(<<<'SQL'

COMMIT;

UPDATE `_imp_emp` SET pw_hash = NULL;
DROP TABLE IF EXISTS `_imp_child`;
DROP TABLE IF EXISTS `_imp_qual`;
DROP TABLE IF EXISTS `_imp_fqual`;

-- 10) Result (one line per Excel row). Remove the staging table afterwards: DROP TABLE `_imp_emp`;
SELECT i.row_no AS excel_row, i.attendance_no, i.full_name,
  CASE
    WHEN i.skip_reason IS NOT NULL THEN CONCAT('SKIPPED: ', i.skip_reason)
    WHEN i.employee_id IS NULL THEN 'SKIPPED: not inserted'
    WHEN EXISTS (SELECT 1 FROM `users` u WHERE u.employee_id = i.employee_id) THEN 'Imported'
    ELSE 'Imported (no portal login: email/NIC already belongs to another login)'
  END AS result
FROM `_imp_emp` i
ORDER BY (i.skip_reason IS NULL AND i.employee_id IS NOT NULL), i.row_no;
SQL);

$out = $opts['out'] ?? preg_replace('/\.(xlsx|xls|ods|csv)$/i', '', $file).'_import.sql';
file_put_contents($out, implode("\n", $sql)."\n");

echo "SQL written: $out\n";
echo "  Employees: $e_count, Children: $c_count, Qualifications: $q_count, Following: $f_count\n";
echo '  Missing masters: '.($opts['create'] ? 'created automatically' : 'rows rejected')."\n";
if ($errors) {
    echo "  Rows with problems were left out (see the list above).\n";
}
echo "Next: phpMyAdmin > select the tenant database > Import (or SQL tab) > run this file.\n";
