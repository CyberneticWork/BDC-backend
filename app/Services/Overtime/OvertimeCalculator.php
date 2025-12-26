<?php

namespace App\Services\Overtime;

use App\Models\employee;
use App\Models\shifts;
use Carbon\Carbon;
use App\Models\ShiftOvertimeRate;

class OvertimeCalculator
{
    /**
     * Calculate overtime hour buckets and monetary amounts for a single shift pairing.
     * @param employee $employee
     * @param shifts $shift
     * @param Carbon $clockIn
     * @param Carbon $clockOut
     * @param bool $isHoliday  // NEW: whether attendance date is a holiday
     */
    public function calculate(employee $employee, shifts $shift, Carbon $clockIn, Carbon $clockOut, bool $isHoliday = false): array
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

        // Get rate model for threshold calculations
        $rateModel = ShiftOvertimeRate::where('shift_id', $shift->id)->whereNull('deleted_at')->first();

        // NEW: Check if we should round up the work end time to OT window end
        $workEndForCalculation = $this->checkAndApplyRoundUp(
            $workEnd,
            $morningRegularWindow,
            $eveningRegularWindow,
            $rateModel
        );

        $hours = [
            'morning_regular' => $this->overlapHours($workStart, $workEndForCalculation, $morningRegularWindow),
            'morning_special' => $this->overlapHours($workStart, $workEndForCalculation, $morningSpecialWindow),
            'evening_regular' => $this->overlapHours($workStart, $workEndForCalculation, $eveningRegularWindow),
            'evening_special' => $this->overlapHours($workStart, $workEndForCalculation, $eveningSpecialWindow),
        ];

        // Track if round-up was applied (for meta info)
        $roundUpApplied = !$workEnd->eq($workEndForCalculation);

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

        // --- IGNORE THRESHOLD LOGIC (chunked multiples) ---
        // Only apply threshold logic if round-up was NOT applied
        if (!$roundUpApplied && $rateModel && !empty($rateModel->ignore_hours_threshold)) {
            $thresholdConfig = $rateModel->ignore_hours_threshold;
            $thHours = (float)($thresholdConfig['hours'] ?? 0);
            $thMinutes = (float)($thresholdConfig['minutes'] ?? 0);
            $thresholdMinutes = (int) round(($thHours * 60.0) + $thMinutes);

            if ($thresholdMinutes > 0) {
                $applyChunkedThreshold = function (float $regular, float $special) use ($thresholdMinutes): array {
                    $totalHours = $regular + $special;
                    if ($totalHours <= 0) {
                        return [0.0, 0.0];
                    }
                    $totalMinutes = (int) round($totalHours * 60.0);
                    $chunks = intdiv($totalMinutes, $thresholdMinutes);
                    $allowedMinutes = $chunks * $thresholdMinutes;
                    if ($allowedMinutes <= 0) {
                        return [0.0, 0.0];
                    }
                    $regMinutesRaw = ($regular * 60.0);
                    $specMinutesRaw = ($special * 60.0);
                    $sumRaw = max(1.0, $regMinutesRaw + $specMinutesRaw);
                    $regMinutes = (int) round(($regMinutesRaw / $sumRaw) * $allowedMinutes);
                    $specMinutes = $allowedMinutes - $regMinutes;
                    return [round($regMinutes / 60.0, 2), round($specMinutes / 60.0, 2)];
                };

                [$hours['morning_regular'], $hours['morning_special']] =
                    $applyChunkedThreshold($hours['morning_regular'], $hours['morning_special']);

                [$hours['evening_regular'], $hours['evening_special']] =
                    $applyChunkedThreshold($hours['evening_regular'], $hours['evening_special']);
            }
        }
        // --- END IGNORE THRESHOLD LOGIC ---

        $hours = array_map(fn ($v) => round((float)$v, 2), $hours);
        $hours['total'] = round(array_sum($hours), 2);

        // NEW RATE CALCULATION (replacing compensation-based individual rates)
        $basicSalary = (float) ($compensation?->basic_salary ?? 0);
        $shiftHoursPerDay = (float) ($rateModel?->shift_hours_per_day ?? 0);
        $workingDaysPerMonth = (float) ($rateModel?->working_days_per_month ?? 0);
        $totalMonthlyHours = $shiftHoursPerDay * $workingDaysPerMonth;
        $baseHourlyRate = $totalMonthlyHours > 0 ? round($basicSalary / $totalMonthlyHours, 6) : 0.0;

        $otMultiplier = (float) ($rateModel?->ot_multiplier ?? 1.5);
        $holidayMultiplier = (float) ($rateModel?->holiday_multiplier ?? 2.0);
        $effectiveMultiplier = $isHoliday ? $holidayMultiplier : $otMultiplier;
        $effectiveOtHourlyRate = round($baseHourlyRate * $effectiveMultiplier, 6);

        // Uniform rate across all buckets
        $amounts = [
            'morning_regular' => round($hours['morning_regular'] * $effectiveOtHourlyRate, 2),
            'morning_special' => round($hours['morning_special'] * $effectiveOtHourlyRate, 2),
            'evening_regular' => round($hours['evening_regular'] * $effectiveOtHourlyRate, 2),
            'evening_special' => round($hours['evening_special'] * $effectiveOtHourlyRate, 2),
        ];
        $amounts['total'] = round(array_sum($amounts), 2);

        return [
            'hours' => $hours,
            'amounts' => $amounts,
            'meta' => [
                'is_holiday' => $isHoliday,
                'base_hourly_rate' => $baseHourlyRate,
                'effective_ot_hourly_rate' => $effectiveOtHourlyRate,
                'ot_multiplier' => $otMultiplier,
                'holiday_multiplier' => $holidayMultiplier,
                'total_monthly_hours' => $totalMonthlyHours,
                'round_up_applied' => $roundUpApplied, // NEW: Track if round-up was applied
            ],
        ];
    }

    /**
     * NEW: Check if the employee's OUT time is within half of the ignore threshold
     * from the OT window end time, and if so, round up to the OT end time.
     * 
     * Logic:
     * - If (OT window end time - employee OUT time) <= (ignore threshold / 2)
     * - Then round the OUT time to the OT window end time
     * - Otherwise, keep the original OUT time
     */
    private function checkAndApplyRoundUp(
        Carbon $workEnd,
        ?array $morningWindow,
        ?array $eveningWindow,
        ?ShiftOvertimeRate $rateModel
    ): Carbon {
        // If no rate model or no threshold configured, return original
        if (!$rateModel || empty($rateModel->ignore_hours_threshold)) {
            return $workEnd->copy();
        }

        $thresholdConfig = $rateModel->ignore_hours_threshold;
        $thHours = (float)($thresholdConfig['hours'] ?? 0);
        $thMinutes = (float)($thresholdConfig['minutes'] ?? 0);
        $thresholdMinutes = (int) round(($thHours * 60.0) + $thMinutes);

        // If threshold is 0, no round-up logic applies
        if ($thresholdMinutes <= 0) {
            return $workEnd->copy();
        }

        // Half threshold in minutes for round-up check
        $halfThresholdMinutes = $thresholdMinutes / 2.0;

        // Check evening window first (most common case for end-of-day)
        if ($eveningWindow) {
            [, $eveningEnd] = $eveningWindow;
            if ($eveningEnd) {
                $roundUpResult = $this->shouldRoundUpToWindowEnd($workEnd, $eveningEnd, $halfThresholdMinutes);
                if ($roundUpResult !== null) {
                    return $roundUpResult;
                }
            }
        }

        // Check morning window
        if ($morningWindow) {
            [, $morningEnd] = $morningWindow;
            if ($morningEnd) {
                $roundUpResult = $this->shouldRoundUpToWindowEnd($workEnd, $morningEnd, $halfThresholdMinutes);
                if ($roundUpResult !== null) {
                    return $roundUpResult;
                }
            }
        }

        // No round-up applicable
        return $workEnd->copy();
    }

    /**
     * Check if workEnd should be rounded up to windowEnd.
     * Returns the rounded Carbon time if applicable, or null if not.
     * 
     * Condition: workEnd < windowEnd AND (windowEnd - workEnd) <= halfThresholdMinutes
     */
    private function shouldRoundUpToWindowEnd(Carbon $workEnd, Carbon $windowEnd, float $halfThresholdMinutes): ?Carbon
    {
        // Only round up if work end is BEFORE window end (employee left early but close to end)
        if ($workEnd->greaterThanOrEqualTo($windowEnd)) {
            return null;
        }

        // Calculate the difference in minutes between window end and work end
        $diffMinutes = $workEnd->diffInMinutes($windowEnd, false);

        // If the difference is within half the threshold, round up
        if ($diffMinutes > 0 && $diffMinutes <= $halfThresholdMinutes) {
            return $windowEnd->copy();
        }

        return null;
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

        // Explicit configured window (any of start/end present)
        if ($shift->morning_ot_start || $shift->morning_ot_end) {
            $startTime = $shift->morning_ot_start ?? $shift->morning_ot_end;        // fallback to end if start missing
            $endTime   = $shift->morning_ot_end   ?? $shift->morning_ot_start;      // fallback to start if end missing
            return $this->makeWindowFromTimes($shiftStart, $startTime, $endTime, allowPast: true);
        }

        // Fallback (legacy) = 12h before shift start
        $start = $shiftStart->copy()->subHours(12);
        return [$start, $shiftStart->copy()];
    }

    private function buildEveningRegularWindow(shifts $shift, ?Carbon $shiftStart, ?Carbon $shiftEnd): ?array
    {
        if (!$shiftEnd) {
            return null;
        }

        // Explicit configured window (any of start/end present)
        if ($shift->night_ot_start || $shift->night_ot_end) {
            $startTime = $shift->night_ot_start ?? $shift->night_ot_end;        // fallback if start missing
            $endTime   = $shift->night_ot_end   ?? $shift->night_ot_start;      // fallback if end missing
            return $this->buildEveningWindowWithTimes($shiftStart ?? $shiftEnd, $shiftEnd, $startTime, $endTime);
        }

        // Fallback (legacy) = 12h after shift end
        $start = $shiftEnd->copy();
        $end = $shiftEnd->copy()->addHours(12);
        return [$start, $end];
    }

    private function buildMorningSpecialWindow(shifts $shift, ?array $regularWindow, ?bool $specialEnabled): ?array
    {
        // Only allow special window if explicitly enabled AND explicit morning window exists (both start/end defined)
        if (
            !$specialEnabled ||
            !$regularWindow ||
            !($shift->morning_ot_start || $shift->morning_ot_end)
        ) {
            return null;
        }

        [$regularStart] = $regularWindow;
        // Special = 12h block immediately preceding configured morning window
        $start = $regularStart->copy()->subHours(12);
        return [$start, $regularStart->copy()];
    }

    private function buildEveningSpecialWindow(shifts $shift, ?array $regularWindow, ?bool $specialEnabled): ?array
    {
        // Only allow special window if explicitly enabled AND explicit evening window exists
        if (
            !$specialEnabled ||
            !$regularWindow ||
            !($shift->night_ot_start || $shift->night_ot_end)
        ) {
            return null;
        }

        [, $regularEnd] = $regularWindow;

        // Start immediately after evening regular end (no extension beyond configured window end trigger times)
        $specialStart = $regularEnd->copy();
        $specialEnd = $specialStart->copy()->addHours(12); // capped to 12h span
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
