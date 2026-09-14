<?php

namespace App\Http\Controllers;

use App\Models\employee;
use App\Models\time_card;
use App\Services\ContractEmployeeScope;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ContractAttendanceReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'search' => 'nullable|string|max:100',
        ]);

        $from = $validated['from_date'];
        $to = $validated['to_date'];

        $employeesQuery = employee::with([
            'employmentType',
            'organizationAssignment.company',
            'organizationAssignment.department',
        ])->where('is_active', 1);

        ContractEmployeeScope::only($employeesQuery);

        if (!empty($validated['company_id']) || !empty($validated['department_id'])) {
            $employeesQuery->whereHas('organizationAssignment', function ($q) use ($validated) {
                if (!empty($validated['company_id'])) {
                    $q->where('company_id', $validated['company_id']);
                }
                if (!empty($validated['department_id'])) {
                    $q->where('department_id', $validated['department_id']);
                }
            });
        }

        if (!empty($validated['search'])) {
            $s = $validated['search'];
            $employeesQuery->where(function ($q) use ($s) {
                $q->where('full_name', 'like', "%{$s}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$s}%")
                    ->orWhere('nic', 'like', "%{$s}%");
            });
        }

        $employees = $employeesQuery->orderBy('full_name')->get();
        $empIds = $employees->pluck('id')->all();

        $cardsByEmpDate = [];
        if (!empty($empIds)) {
            $cards = time_card::query()
                ->whereIn('employee_id', $empIds)
                ->whereNull('deleted_at')
                ->whereBetween('date', [$from, $to])
                ->orderBy('date')
                ->orderBy('time')
                ->get();

            foreach ($cards as $card) {
                $date = $card->date instanceof \DateTimeInterface
                    ? $card->date->format('Y-m-d')
                    : substr((string) $card->date, 0, 10);
                $cardsByEmpDate[$card->employee_id][$date][] = $card;
            }
        }

        $rows = [];
        $totalHours = 0.0;
        $records = 0;

        foreach ($employees as $emp) {
            $org = $emp->organizationAssignment;
            $dates = array_keys($cardsByEmpDate[$emp->id] ?? []);
            sort($dates);

            foreach ($dates as $date) {
                $pair = $this->pairInOut($cardsByEmpDate[$emp->id][$date] ?? []);
                $hours = $this->workingHours($date, $pair['in_time'], $pair['out_time']);
                $totalHours += $hours;
                $records++;

                $rows[] = [
                    'id' => $emp->id . '_' . $date,
                    'employee_id' => $emp->id,
                    'emp_no' => $emp->attendance_employee_no,
                    'employee_name' => $emp->full_name,
                    'company' => $org?->company?->name,
                    'department' => $org?->department?->name,
                    'employment_type' => $emp->employmentType?->name,
                    'date' => $date,
                    'in_time' => $pair['in_time'],
                    'out_time' => $pair['out_time'],
                    'working_hours' => round($hours, 2),
                    'working_hours_label' => $this->formatHours($hours),
                ];
            }
        }

        return response()->json([
            'from' => $from,
            'to' => $to,
            'data' => $rows,
            'totals' => [
                'records' => $records,
                'employees' => $employees->count(),
                'working_hours' => round($totalHours, 2),
                'working_hours_label' => $this->formatHours($totalHours),
            ],
        ]);
    }

    private function pairInOut(array $cards): array
    {
        $inTime = null;
        $outTime = null;

        foreach ($cards as $card) {
            $status = strtolower(trim((string) ($card->status ?? '')));
            $entry = (int) ($card->entry ?? -1);
            $time = $this->normalizeTime($card->time);
            if (!$time) {
                continue;
            }

            $isIn = in_array($status, ['in', 'late coming'], true) || $entry === 1;
            $isOut = in_array($status, ['out', 'early out'], true) || $entry === 2 || $entry === 0;

            if ($isIn && ($inTime === null || strcmp($time, $inTime) < 0)) {
                $inTime = $time;
            }
            if ($isOut && ($outTime === null || strcmp($time, $outTime) > 0)) {
                $outTime = $time;
            }
        }

        return ['in_time' => $inTime, 'out_time' => $outTime];
    }

    private function workingHours(string $date, ?string $inTime, ?string $outTime): float
    {
        if (!$inTime || !$outTime) {
            return 0.0;
        }

        try {
            $in = Carbon::parse($date . ' ' . $inTime);
            $out = Carbon::parse($date . ' ' . $outTime);
            if ($out->lt($in)) {
                $out->addDay();
            }

            return round($out->diffInSeconds($in) / 3600, 2);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private function formatHours(float $hours): string
    {
        if ($hours <= 0) {
            return '';
        }
        $totalMinutes = (int) round($hours * 60);
        $h = intdiv($totalMinutes, 60);
        $m = $totalMinutes % 60;

        return sprintf('%d:%02d', $h, $m);
    }

    private function normalizeTime($time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }
        $raw = (string) $time;
        if (preg_match('/(\d{1,2}:\d{2}(?::\d{2})?)/', $raw, $m)) {
            $parts = explode(':', $m[1]);
            $h = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $i = $parts[1] ?? '00';
            $s = $parts[2] ?? '00';

            return "{$h}:{$i}:{$s}";
        }

        return null;
    }
}
