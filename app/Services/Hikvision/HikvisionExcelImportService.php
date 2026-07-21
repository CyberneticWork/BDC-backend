<?php

namespace App\Services\Hikvision;

use App\Http\Controllers\TimeCardController;
use App\Models\employee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class HikvisionExcelImportService
{
    /**
     * Import attendance exported from iVMS-4200 / Hik-Connect.
     * Supports common column layouts and falls back to HR template format.
     */
    public function import(string $filePath, int $companyId, string $fromDate, ?string $toDate = null): array
    {
        $rows = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\ToArray {
            public function array(array $array) {}
        }, $filePath)[0] ?? [];

        if (count($rows) < 2) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['File is empty or has no data rows']];
        }

        $headerRow = array_map(fn ($v) => strtolower(trim((string) $v)), $rows[0]);
        $map = $this->detectColumns($headerRow);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $parsed = $this->parseRow($row, $map, $headerRow);
            if (!$parsed) {
                $skipped++;
                continue;
            }

            if ($parsed['date'] < $fromDate || ($toDate && $parsed['date'] > $toDate)) {
                $skipped++;
                continue;
            }

            $employee = employee::where(function ($q) use ($parsed) {
                $id = $parsed['employee_identifier'];
                $q->whereRaw('LOWER(nic) = ?', [strtolower($id)])
                    ->orWhere('attendance_employee_no', $id);
            })->whereHas('organizationAssignment', fn ($q) => $q->where('company_id', $companyId))
                ->first();

            if (!$employee) {
                $errors[] = "Row " . ($i + 1) . ": Employee not found ({$parsed['employee_identifier']})";
                $skipped++;
                continue;
            }

            $request = Request::create('/api/attendance', 'POST', [
                'empno' => $employee->attendance_employee_no,
                'date' => $parsed['date'],
                'time' => $parsed['time'],
            ]);

            $response = app(TimeCardController::class)->attendance($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 201) {
                $imported++;
            } elseif ($statusCode === 409) {
                $skipped++;
            } else {
                $payload = json_decode($response->getContent(), true);
                $msg = $payload['message'] ?? 'Rejected';
                $errors[] = "Row " . ($i + 1) . ": {$msg}";
                $skipped++;
            }
        }

        return compact('imported', 'skipped', 'errors');
    }

    private function detectColumns(array $headerRow): array
    {
        $find = function (array $needles) use ($headerRow) {
            foreach ($needles as $needle) {
                foreach ($headerRow as $idx => $col) {
                    if (str_contains($col, $needle)) {
                        return $idx;
                    }
                }
            }
            return null;
        };

        return [
            'employee' => $find(['person no', 'employee no', 'emp no', 'person id', 'employee id', 'employeenic', 'nic']),
            'date' => $find(['date time', 'datetime', 'punch time', 'time and attendance', 'date']),
            'time' => $find(['time']),
            'status' => $find(['attendance status', 'status', 'in/out', 'direction']),
        ];
    }

    private function parseRow(array $row, array $map, array $headerRow): ?array
    {
        $employeeIdx = $map['employee'] ?? 0;
        $employeeIdentifier = trim((string) ($row[$employeeIdx] ?? ''));
        if ($employeeIdentifier === '') {
            return null;
        }

        $dateIdx = $map['date'];
        $timeIdx = $map['time'];

        $date = null;
        $time = null;

        if ($dateIdx !== null) {
            $dateTimeRaw = trim((string) ($row[$dateIdx] ?? ''));
            if ($dateTimeRaw !== '') {
                if (is_numeric($dateTimeRaw)) {
                    $unix = ((float) $dateTimeRaw - 25569) * 86400;
                    $dt = Carbon::createFromTimestampUTC((int) $unix);
                    $date = $dt->format('Y-m-d');
                    $time = $dt->format('H:i:s');
                } else {
                    try {
                        $dt = Carbon::parse($dateTimeRaw);
                        $date = $dt->format('Y-m-d');
                        $time = $dt->format('H:i:s');
                    } catch (\Throwable) {
                        $date = date('Y-m-d', strtotime($dateTimeRaw));
                    }
                }
            }
        }

        if (!$date && isset($row[1])) {
            $excelDate = trim((string) $row[1]);
            if (is_numeric($excelDate)) {
                $date = gmdate('Y-m-d', ((float) $excelDate - 25569) * 86400);
            } else {
                $date = date('Y-m-d', strtotime($excelDate));
            }
        }

        if (!$time && $timeIdx !== null) {
            $time = $this->normalizeTime($row[$timeIdx] ?? '');
        }

        if (!$time && isset($row[2])) {
            $time = $this->normalizeTime($row[2]);
        }

        if (!$date || !$time) {
            return null;
        }

        return [
            'employee_identifier' => $employeeIdentifier,
            'date' => $date,
            'time' => $time,
        ];
    }

    private function normalizeTime(mixed $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $seconds = round((float) $raw * 24 * 60 * 60);
            return sprintf(
                '%02d:%02d:%02d',
                intdiv($seconds, 3600),
                intdiv($seconds % 3600, 60),
                $seconds % 60
            );
        }

        try {
            return Carbon::parse($raw)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
