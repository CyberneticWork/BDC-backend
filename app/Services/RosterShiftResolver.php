<?php

namespace App\Services;

use App\Models\employee;
use App\Models\Roster;
use App\Models\shifts;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class RosterShiftResolver
{
    public function assignmentsFor(employee $employee, string $date): Collection
    {
        $org = $employee->organizationAssignment;
        if (!$org) {
            return collect();
        }

        $multi = CompanyProcessSettings::usesShiftRoster($employee);
        $rows = $this->queryLevel($date, fn ($q) => $q->where('employee_id', $employee->id), $multi);

        if ($rows->isEmpty() && $org->sub_department_id) {
            $rows = $this->queryLevel($date, function ($q) use ($org) {
                $q->where('sub_department_id', $org->sub_department_id)->whereNull('employee_id');
            }, $multi);
        }

        if ($rows->isEmpty() && $org->department_id) {
            $rows = $this->queryLevel($date, function ($q) use ($org) {
                $q->where('department_id', $org->department_id)
                    ->whereNull('employee_id')
                    ->whereNull('sub_department_id');
            }, $multi);
        }

        if ($rows->isEmpty() && $org->company_id) {
            $rows = $this->queryLevel($date, function ($q) use ($org) {
                $q->where('company_id', $org->company_id)
                    ->whereNull('employee_id')
                    ->whereNull('sub_department_id')
                    ->whereNull('department_id');
            }, $multi);
        }

        return $rows
            ->map(function ($roster) {
                $shift = shifts::find($roster->shift_code);
                return $shift ? ['roster' => $roster, 'shift' => $shift] : null;
            })
            ->filter()
            ->unique(fn ($row) => (int) $row['shift']->id)
            ->sortBy(fn ($row) => (string) $row['shift']->start_time)
            ->values();
    }

    public function primary(employee $employee, string $date): array
    {
        $items = $this->assignmentsFor($employee, $date);
        if ($items->isEmpty()) {
            return [null, null];
        }
        $row = $items->first();

        return [$row['roster'], $row['shift']];
    }

    public function match(employee $employee, string $date, string $time, string $kind = 'in'): array
    {
        $items = $this->assignmentsFor($employee, $date);
        if ($items->isEmpty()) {
            return [null, null];
        }
        if ($items->count() === 1 || !CompanyProcessSettings::usesShiftRoster($employee)) {
            $row = $items->first();
            return [$row['roster'], $row['shift']];
        }

        $punch = Carbon::parse($date . ' ' . $time);
        $best = null;
        $bestScore = null;
        foreach ($items as $row) {
            [$start, $end] = $this->window($date, $row['shift']);
            $anchor = $kind === 'out' ? $end : $start;
            $inWindow = $punch->between($start->copy()->subHours(4), $end->copy()->addHours(4));
            $score = abs($punch->diffInSeconds($anchor));
            if (!$inWindow) {
                $score += 86400;
            }
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return [$best['roster'], $best['shift']];
    }

    public function window(string $date, shifts $shift): array
    {
        $start = Carbon::parse($date . ' ' . $shift->start_time);
        $end = Carbon::parse($date . ' ' . $shift->end_time);
        if ($end->lte($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    public function clippedHours(Carbon $in, Carbon $out, shifts $shift): float
    {
        [$start, $end] = $this->window($in->format('Y-m-d'), $shift);
        if ($out->lte($in)) {
            $out = $out->copy()->addDay();
        }
        $from = $in->copy()->max($start);
        $to = $out->copy()->min($end);
        if ($to->lte($from)) {
            return 0.0;
        }

        return round($from->floatDiffInHours($to), 2);
    }

    private function queryLevel(string $date, callable $scope, bool $multi)
    {
        $model = Roster::query();
        $query = $model->whereNull('deleted_at');
        $scope($query);
        if (Schema::hasColumn('rosters', 'status')) {
            $query->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'Active');
            });
        }
        $query->where(function ($q) use ($date) {
            $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
        });

        if ($multi) {
            return $query->orderBy('id')->get();
        }

        $one = $query->orderByDesc('id')->first();

        return $one ? collect([$one]) : collect();
    }
}
