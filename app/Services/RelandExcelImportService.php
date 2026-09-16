<?php

namespace App\Services;

use App\Http\Controllers\TimeCardController;
use App\Models\company;
use App\Models\employee;
use App\Models\time_card;
use App\Services\TimeCardAuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class RelandExcelImportService
{
    public function import(string $filePath, int $companyId, string $fromDate, ?string $toDate = null): array
    {
        $company = company::findOrFail($companyId);
        if (!CompanyProcessSettings::usesRelandExcelImport($company)) {
            throw new \RuntimeException('Reland Excel import is not enabled for this company. Turn it on in Cybernetic Admin.');
        }

        $sheet = IOFactory::load($filePath)->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 2) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['File is empty or has no data rows']];
        }

        $header = array_map(fn ($v) => strtolower(trim((string) $v)), $rows[0] ?? []);
        if (!$this->looksLikeReland($header)) {
            throw new \RuntimeException('This is not a Reland Raw Clock-InOut Log. Expected columns such as User ID, Enroll ID, Date and Time.');
        }

        $map = $this->columnMap($header);
        $punches = [];
        $errors = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i] ?? [];
            $parsed = $this->parseRow($row, $map);
            if (!$parsed) {
                continue;
            }
            if ($parsed['date'] < $fromDate || ($toDate && $parsed['date'] > $toDate)) {
                continue;
            }
            $punches[] = $parsed + ['excel_row' => $i + 1];
        }

        usort($punches, function ($a, $b) {
            $cmp = strcmp($a['date'] . $a['time'], $b['date'] . $b['time']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) $a['employee_no'], (string) $b['employee_no']);
        });

        $imported = 0;
        $skipped = 0;
        $lastAt = [];

        foreach ($punches as $punch) {
            $employee = $this->findEmployee($punch['employee_no'], $companyId, $punch['name'] ?? '');
            if (!$employee) {
                $errors[] = 'Row ' . $punch['excel_row'] . ': Employee not found (Reland ID ' . $punch['employee_no'] . '). Reland User ID must match attendance number.';
                $skipped++;
                continue;
            }

            $stamp = $punch['date'] . ' ' . $punch['time'];
            $key = $employee->id;
            if (isset($lastAt[$key])) {
                $prev = Carbon::parse($lastAt[$key]);
                $cur = Carbon::parse($stamp);
                if (abs($cur->diffInSeconds($prev)) < 300) {
                    $skipped++;
                    continue;
                }
            }
            $lastAt[$key] = $stamp;

            $request = Request::create('/api/attendance', 'POST', [
                'empno' => $employee->attendance_employee_no,
                'date' => $punch['date'],
                'time' => $punch['time'],
            ]);
            $response = app(TimeCardController::class)->attendance($request);
            $code = $response->getStatusCode();

            if ($code === 201) {
                $imported++;
                continue;
            }

            if ($code === 409) {
                $skipped++;
                continue;
            }

            $payload = json_decode($response->getContent(), true);
            $message = (string) ($payload['message'] ?? 'Rejected');
            if (str_contains(strtolower($message), 'roster') || str_contains(strtolower($message), 'shift')) {
                if ($this->createFallbackCard($employee, $punch)) {
                    $imported++;
                } else {
                    $skipped++;
                }
                continue;
            }

            $errors[] = 'Row ' . $punch['excel_row'] . ': ' . $message;
            $skipped++;
        }

        try {
            app(RecalculateOvertimeFromPunches::class)->run($companyId);
        } catch (\Throwable) {
            // punches still saved even if OT rebuild fails
        }

        return compact('imported', 'skipped', 'errors');
    }

    private function looksLikeReland(array $header): bool
    {
        $joined = implode(' ', $header);
        return str_contains($joined, 'user id')
            || str_contains($joined, 'enroll id')
            || str_contains($joined, 'att type')
            || str_contains($joined, 'verify mode');
    }

    private function columnMap(array $header): array
    {
        $find = function (array $needles) use ($header) {
            foreach ($needles as $needle) {
                foreach ($header as $idx => $col) {
                    if ($col === $needle || str_contains($col, $needle)) {
                        return (int) $idx;
                    }
                }
            }
            return null;
        };

        return [
            'user_id' => $find(['user id', 'userid']),
            'enroll_id' => $find(['enroll id', 'enrollid']),
            'name' => $find(['name']),
            'date' => $find(['date']),
            'time' => $find(['time']),
        ];
    }

    private function parseRow(array $row, array $map): ?array
    {
        $userId = trim((string) ($row[$map['user_id'] ?? 1] ?? ''));
        $enrollId = trim((string) ($row[$map['enroll_id'] ?? 3] ?? ''));
        $name = trim((string) ($row[$map['name'] ?? 2] ?? ''));
        $employeeNo = $userId !== '' && strcasecmp($userId, 'null') !== 0 ? $userId : $enrollId;
        if ($employeeNo === '' || strcasecmp($employeeNo, 'null') === 0) {
            $employeeNo = $name;
        }
        if ($employeeNo === '' || strcasecmp($employeeNo, 'null') === 0) {
            return null;
        }

        $date = $this->parseDate($row[$map['date'] ?? 6] ?? null);
        $time = $this->parseTime($row[$map['time'] ?? 7] ?? null);
        if (!$date || !$time) {
            return null;
        }

        return [
            'employee_no' => preg_replace('/\.0$/', '', $employeeNo),
            'name' => $name,
            'date' => $date,
            'time' => $time,
        ];
    }

    private function parseDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw) && (float) $raw > 20000) {
            return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
        }
        try {
            return Carbon::parse((string) $raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseTime(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw) && (float) $raw < 1.5) {
            $seconds = (int) round((float) $raw * 24 * 60 * 60);
            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600) % 24, intdiv($seconds % 3600, 60), $seconds % 60);
        }
        try {
            return Carbon::parse((string) $raw)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function findEmployee(string $identifier, int $companyId, string $name = ''): ?employee
    {
        $trimmed = trim($identifier);
        $unpadded = ltrim($trimmed, '0');
        if ($unpadded === '') {
            $unpadded = '0';
        }
        $ids = array_values(array_unique(array_filter([$trimmed, $unpadded])));

        $matchIds = function ($query) use ($ids) {
            $query->where(function ($q) use ($ids) {
                foreach ($ids as $id) {
                    $q->orWhere('attendance_employee_no', $id)
                        ->orWhereRaw('LOWER(nic) = ?', [strtolower($id)]);
                }
            });
        };

        $employee = employee::query()->where($matchIds)
            ->whereHas('organizationAssignment', fn ($q) => $q->where('company_id', $companyId))
            ->first();
        if ($employee) {
            return $employee;
        }

        $employee = employee::query()->where($matchIds)->first();
        if ($employee) {
            return $employee;
        }

        $name = trim($name);
        if ($name !== '' && !ctype_digit($name) && strlen($name) >= 4) {
            $like = '%' . preg_replace('/\s+/', '%', $name) . '%';
            $employee = employee::query()
                ->where(function ($q) use ($like, $name) {
                    $q->whereRaw('LOWER(full_name) like ?', [strtolower($like)])
                        ->orWhereRaw('LOWER(display_name) = ?', [strtolower($name)]);
                })
                ->first();
        }

        return $employee;
    }

    private function createFallbackCard(employee $employee, array $punch): bool
    {
        $exists = time_card::where('employee_id', $employee->id)
            ->where('date', $punch['date'])
            ->where('time', $punch['time'])
            ->exists();
        if ($exists) {
            return false;
        }

        $openIn = time_card::where('employee_id', $employee->id)
            ->where('date', $punch['date'])
            ->whereIn('status', ['IN', 'Late Coming'])
            ->orderBy('time')
            ->get()
            ->filter(function ($in) use ($employee, $punch) {
                return !time_card::where('employee_id', $employee->id)
                    ->where('date', $punch['date'])
                    ->whereIn('status', ['OUT', 'Early OUT'])
                    ->where('time', '>', $in->time)
                    ->exists();
            })
            ->last();

        $status = $openIn ? 'OUT' : 'IN';
        $payload = [
            'employee_id' => $employee->id,
            'time' => $punch['time'],
            'date' => $punch['date'],
            'entry' => $status === 'IN' ? 1 : 0,
            'status' => $status,
            'actual_date' => $punch['date'],
            'approval_status' => 'Pending',
        ];
        if (Schema::hasColumn('time_cards', 'entry_source')) {
            $payload['entry_source'] = 'import';
        }

        $card = time_card::create($payload);
        TimeCardAuditService::log($card, 'created', 'Imported from Reland Excel', null, TimeCardAuditService::snapshot($card), 'import');

        return true;
    }
}
