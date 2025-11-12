<?php

namespace App\Services\Overtime;

use App\Models\employee;
use App\Models\shifts;
use Carbon\Carbon;

class OvertimeCalculator
{
    /**
     * Calculate overtime hour buckets and monetary amounts for a single shift pairing.
     */
    public function calculate(employee $employee, shifts $shift, Carbon $clockIn, Carbon $clockOut): array
    {
        $workStart = $clockIn->copy();
        $workEnd = $clockOut->copy();

        if ($workEnd->lessThanOrEqualTo($workStart)) {
            $workEnd->addDay();
        }

        $compensation = $employee->compensation;

        $shiftStart = $this->combineDateTime($workStart, $shift->start_time);
        $shiftEnd = $this->combineShiftEnd($shiftStart, $shift->end_time);

        $morningRegularWindow = $this->buildMorningRegularWindow($shift, $shiftStart);
        $morningSpecialWindow = $this->buildMorningSpecialWindow($shift, $morningRegularWindow, $compensation?->ot_morning_special);

        $eveningRegularWindow = $this->buildEveningRegularWindow($shift, $shiftStart, $shiftEnd);
        $eveningSpecialWindow = $this->buildEveningSpecialWindow($shift, $eveningRegularWindow, $compensation?->ot_evening_special);

        $hours = [
            'morning_regular' => $this->overlapHours($workStart, $workEnd, $morningRegularWindow),
            'morning_special' => $this->overlapHours($workStart, $workEnd, $morningSpecialWindow),
            'evening_regular' => $this->overlapHours($workStart, $workEnd, $eveningRegularWindow),
            'evening_special' => $this->overlapHours($workStart, $workEnd, $eveningSpecialWindow),
        ];

        if (!($compensation?->ot_morning)) {
            $hours['morning_regular'] = 0.0;
        }

        if (!($compensation?->ot_morning_special)) {
            $hours['morning_special'] = 0.0;
        }

        if (!($compensation?->ot_evening)) {
            $hours['evening_regular'] = 0.0;
        }

        if (!($compensation?->ot_evening_special)) {
            $hours['evening_special'] = 0.0;
        }

        $hours = array_map(fn ($value) => round((float) $value, 2), $hours);
        $hours['total'] = round(array_sum($hours), 2);

        $rates = [
            'morning_regular' => (float) ($compensation?->ot_morning_rate ?? 0),
            'morning_special' => (float) ($compensation?->ot_morning_rate_special ?? $compensation?->ot_morning_rate ?? 0),
            'evening_regular' => (float) ($compensation?->ot_night_rate ?? 0),
            'evening_special' => (float) ($compensation?->ot_night_rate_special ?? $compensation?->ot_night_rate ?? 0),
        ];

        $amounts = [
            'morning_regular' => round($hours['morning_regular'] * $rates['morning_regular'], 2),
            'morning_special' => round($hours['morning_special'] * $rates['morning_special'], 2),
            'evening_regular' => round($hours['evening_regular'] * $rates['evening_regular'], 2),
            'evening_special' => round($hours['evening_special'] * $rates['evening_special'], 2),
        ];

        $amounts['total'] = round(array_sum($amounts), 2);

        return [
            'hours' => $hours,
            'amounts' => $amounts,
        ];
    }

    private function combineDateTime(Carbon $reference, ?string $time): ?Carbon
    {
        if (!$time) {
            return null;
        }

        return Carbon::parse($reference->format('Y-m-d') . ' ' . $time);
    }

    private function combineShiftEnd(?Carbon $shiftStart, ?string $endTime): ?Carbon
    {
        if (!$shiftStart || !$endTime) {
            return null;
        }

        $candidate = Carbon::parse($shiftStart->format('Y-m-d') . ' ' . $endTime);
        if ($candidate->lessThanOrEqualTo($shiftStart)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private function buildMorningRegularWindow(shifts $shift, ?Carbon $shiftStart): ?array
    {
        if (!$shiftStart) {
            return null;
        }

        if ($shift->morning_ot_start && $shift->morning_ot_end) {
            return $this->makeWindowFromTimes($shiftStart, $shift->morning_ot_start, $shift->morning_ot_end, allowPast: true);
        }

        $start = $shiftStart->copy()->subHours(12);
        return [$start, $shiftStart->copy()];
    }

    private function buildMorningSpecialWindow(shifts $shift, ?array $regularWindow, ?bool $specialEnabled): ?array
    {
        if (!$specialEnabled || !$regularWindow || !($shift->morning_ot_start && $shift->morning_ot_end)) {
            return null;
        }

        [$regularStart] = $regularWindow;
        $start = $regularStart->copy()->subHours(12);
        return [$start, $regularStart->copy()];
    }

    private function buildEveningRegularWindow(shifts $shift, ?Carbon $shiftStart, ?Carbon $shiftEnd): ?array
    {
        if (!$shiftEnd) {
            return null;
        }

        if ($shift->night_ot_start) {
            return $this->buildEveningWindowWithTimes($shiftStart ?? $shiftEnd, $shiftEnd, $shift->night_ot_start, $shift->night_ot_end);
        }

        $start = $shiftEnd->copy();
        $end = $shiftEnd->copy()->addHours(12);
        return [$start, $end];
    }

    private function buildEveningSpecialWindow(shifts $shift, ?array $regularWindow, ?bool $specialEnabled): ?array
    {
        if (!$specialEnabled || !$regularWindow) {
            return null;
        }

        [, $regularEnd] = $regularWindow;

        if ($shift->night_ot_end) {
            $specialStart = $this->alignTime($regularEnd, $shift->night_ot_end, $regularEnd);
        } else {
            $specialStart = $regularEnd->copy();
        }

        if ($specialStart->lessThan($regularEnd)) {
            $specialStart = $regularEnd->copy();
        }

        $specialEnd = $specialStart->copy()->addHours(12);
        return [$specialStart, $specialEnd];
    }

    private function makeWindowFromTimes(Carbon $base, string $startTime, string $endTime, bool $allowPast = false): array
    {
        $start = Carbon::parse($base->format('Y-m-d') . ' ' . $startTime);
        if (!$allowPast) {
            while ($start->lessThan($base)) {
                $start->addDay();
            }
        }

        $end = Carbon::parse($base->format('Y-m-d') . ' ' . $endTime);
        while ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    private function buildEveningWindowWithTimes(Carbon $base, Carbon $shiftEnd, string $startTime, ?string $endTime): array
    {
        $start = Carbon::parse($base->format('Y-m-d') . ' ' . $startTime);
        while ($start->lessThan($shiftEnd)) {
            $start->addDay();
        }

        if ($endTime) {
            $end = Carbon::parse($base->format('Y-m-d') . ' ' . $endTime);
            while ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }
        } else {
            $end = $start->copy()->addHours(12);
        }

        return [$start, $end];
    }

    private function alignTime(Carbon $anchor, string $time, Carbon $notBefore): Carbon
    {
        $candidate = Carbon::parse($anchor->format('Y-m-d') . ' ' . $time);
        while ($candidate->lessThan($notBefore)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private function overlapHours(Carbon $workStart, Carbon $workEnd, ?array $window): float
    {
        if (!$window) {
            return 0.0;
        }

        [$windowStart, $windowEnd] = $window;
        if (!$windowStart || !$windowEnd) {
            return 0.0;
        }

        $start = $workStart->greaterThan($windowStart) ? $workStart->copy() : $windowStart->copy();
        $end = $workEnd->lessThan($windowEnd) ? $workEnd->copy() : $windowEnd->copy();

        if ($end->lessThanOrEqualTo($start)) {
            return 0.0;
        }

        return round($start->floatDiffInHours($end), 2);
    }
}
