<?php

namespace App\Services\Overtime;

use App\Models\employee;
use App\Models\shifts;
use App\Models\ShiftOvertimeRate;
use Carbon\Carbon;

class OvertimeCalculator
{
    public function calculate(
        employee $employee,
        shifts $shift,
        Carbon $clockIn,
        Carbon $clockOut,
        bool $isHoliday = false
    ): array {
        $workStart = $clockIn->copy();
        $workEnd = $clockOut->copy();

        // දවස් මාරු වන Shifts (Night Shifts) සඳහා හැසිරවීම
        if ($workEnd->lessThanOrEqualTo($workStart)) {
            $workEnd->addDay();
        }

        $compensation = $employee->compensation;

        // 🔥 පන්ච් එකට අදාළ ෂිෆ්ට් එකේ පටන් ගන්නා සහ ඉවර වෙන වෙලාවන් සකස් කිරීම
        $shiftStart = $this->combineDateTime($workStart, $shift->start_time);
        $shiftEnd = $this->combineShiftEnd($shiftStart, $shift->end_time);

        // Rounding logic සඳහා සම්පූර්ණ විනාඩි ගණන ගැනීම
        $exactTotalMinutes = $workStart->diffInMinutes($workEnd);
        $roundedTotalMinutes = round($exactTotalMinutes / 30) * 30; 
        $totalWorkedHours = round($roundedTotalMinutes / 60, 2);

        // =====================================================================
        // 🔥 HOLIDAY LOGIC (SHIFT VS OUTSIDE SPLITTING)
        // =====================================================================
        if ($isHoliday) {
            $basicSalary = (float) ($compensation?->basic_salary ?? 0);
            $baseHourlyRate = round($basicSalary / 240, 6);

            $regularOtHourlyRate = round($baseHourlyRate * 1.5, 6); // Shift ඇතුළත
            $holidayOtHourlyRate = round($baseHourlyRate * 2.0, 6); // Shift පිටත

            $shiftWorkedHours = 0.0;

            // Roster එකේ Shift එක සහ පන්ච් එක සසඳා Shift ඇතුළත කාලය සෙවීම
            if ($shiftStart && $shiftEnd) {
                $overlapStart = $workStart->max($shiftStart);
                $overlapEnd = $workEnd->min($shiftEnd);

                if ($overlapStart->lessThan($overlapEnd)) {
                    $exactShiftMinutes = $overlapStart->diffInMinutes($overlapEnd);
                    $shiftWorkedHours = round((round($exactShiftMinutes / 30) * 30) / 60, 2);
                }
            }

            // Shift එකෙන් පිට ඉතිරි කාලය (Holiday Out)
            $outsideShiftHours = max(0, $totalWorkedHours - $shiftWorkedHours);

            $shiftAmount = round($shiftWorkedHours * $regularOtHourlyRate, 2);
            $outsideShiftAmount = round($outsideShiftHours * $holidayOtHourlyRate, 2);
            $totalHolidayAmount = $shiftAmount + $outsideShiftAmount;

            return [
                'hours' => [
                    'morning_regular' => 0.0,
                    'morning_special' => 0.0,
                    'evening_regular' => 0.0,
                    'evening_special' => 0.0,
                    'holiday_shift_hours' => $shiftWorkedHours,
                    'holiday_outside_hours' => $outsideShiftHours,
                    'holiday' => $totalWorkedHours,
                    'total' => $totalWorkedHours,
                ],
                'amounts' => [
                    'morning_regular' => 0.0,
                    'evening_regular' => 0.0,
                    'holiday_shift_amount' => $shiftAmount,
                    'holiday_outside_amount' => $outsideShiftAmount,
                    'holiday' => $totalHolidayAmount,
                    'total' => $totalHolidayAmount,
                ],
                'meta' => [ 'is_holiday' => true, 'base_hourly_rate' => $baseHourlyRate ]
            ];
        }

        // =====================================================================
        // NORMAL DAY LOGIC (දිනය නිවාඩුවක් නොවේ නම් පමණක්)
        // =====================================================================
        $morningWindow = [$shiftStart->copy()->subHours(4), $shiftStart];
        $eveningWindow = [$shiftEnd, $shiftEnd->copy()->addHours(6)];

        $mHrs = $this->overlapHours($workStart, $workEnd, $morningWindow);
        $eHrs = $this->overlapHours($workStart, $workEnd, $eveningWindow);

        $mRate = (float) ($compensation?->ot_morning_rate ?? 0);
        $eRate = (float) ($compensation?->ot_night_rate ?? 0);

        return [
            'hours' => [
                'morning_regular' => $mHrs,
                'morning_special' => 0.0,
                'evening_regular' => $eHrs,
                'evening_special' => 0.0,
                'holiday_shift_hours' => 0.0,
                'holiday_outside_hours' => 0.0,
                'total' => round($mHrs + $eHrs, 2),
            ],
            'amounts' => [
                'morning_regular' => round($mHrs * $mRate, 2),
                'evening_regular' => round($eHrs * $eRate, 2),
                'total' => round(($mHrs * $mRate) + ($eHrs * $eRate), 2),
            ],
            'meta' => [ 'is_holiday' => false ]
        ];
    }

    private function combineDateTime(Carbon $ref, ?string $time): ?Carbon { 
        return $time ? Carbon::parse($ref->format('Y-m-d') . ' ' . $time) : null; 
    }
    
    private function combineShiftEnd(?Carbon $start, ?string $time): ?Carbon {
        if (!$start || !$time) return null;
        $c = Carbon::parse($start->format('Y-m-d') . ' ' . $time);
        if ($c->lte($start)) $c->addDay();
        return $c;
    }

    private function overlapHours(Carbon $ws, Carbon $we, array $win): float {
        $st = $ws->max($win[0]); 
        $en = $we->min($win[1]);
        if ($en->lte($st)) return 0.0;
        return round((round($st->diffInMinutes($en) / 30) * 30) / 60, 2);
    }
}
/*
namespace App\Services\Overtime;

use App\Models\employee;
use App\Models\shifts;
use App\Models\ShiftOvertimeRate;
use Carbon\Carbon;

class OvertimeCalculator
{
    public function calculate(
        employee $employee,
        shifts $shift,
        Carbon $clockIn,
        Carbon $clockOut,
        bool $isHoliday = false
    ): array {
        $workStart = $clockIn->copy();
        $workEnd = $clockOut->copy();

        // Cross-day protection
        if ($workEnd->lessThanOrEqualTo($workStart)) {
            $workEnd->addDay();
        }

        $compensation = $employee->compensation;

        $shiftStart = $this->combineDateTime($workStart, $shift->start_time);
        $shiftEnd = $this->combineShiftEnd($shiftStart, $shift->end_time);

        $rateModel = ShiftOvertimeRate::where('shift_id', $shift->id)
            ->whereNull('deleted_at')
            ->first();

        // Build OT windows only from explicit shift config
        $morningRegularWindow = $this->buildMorningRegularWindow($shift, $shiftStart);
        $morningSpecialWindow = $this->buildMorningSpecialWindow(
            $shift,
            $morningRegularWindow,
            (bool) ($compensation?->ot_morning_special)
        );

        $eveningRegularWindow = $this->buildEveningRegularWindow($shift, $shiftStart, $shiftEnd);
        $eveningSpecialWindow = $this->buildEveningSpecialWindow(
            $shift,
            $eveningRegularWindow,
            (bool) ($compensation?->ot_evening_special)
        );

        // Optional round-up to OT window end if within half threshold
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

        $roundUpApplied = !$workEnd->eq($workEndForCalculation);
        

        
        // HOLIDAY: full worked hours can become OT
        if ($isHoliday) {
            // Holiday  (Round to nearest lower 30 mins)
            $exactHolidayMinutes = $workStart->diffInMinutes($workEndForCalculation);
            $roundedHolidayMinutes = floor($exactHolidayMinutes / 30) * 30;
            $totalHours = round($roundedHolidayMinutes / 60, 2);

            $basicSalary = (float) ($compensation?->basic_salary ?? 0);
            $shiftHoursPerDay = (float) ($rateModel?->shift_hours_per_day ?? 0);
            $workingDaysPerMonth = (float) ($rateModel?->working_days_per_month ?? 0);
            $totalMonthlyHours = $shiftHoursPerDay * $workingDaysPerMonth;

            $baseHourlyRate = $totalMonthlyHours > 0
                ? round($basicSalary / $totalMonthlyHours, 6)
                : 0.0;

            $holidayMultiplier = (float) ($rateModel?->holiday_multiplier ?? 2.0);
            $effectiveOtHourlyRate = round($baseHourlyRate * $holidayMultiplier, 6);

            $hoursObj = [
                'morning_regular' => 0.0,
                'morning_special' => 0.0,
                'evening_regular' => 0.0,
                'evening_special' => 0.0,
                'holiday' => $totalHours,
                'total' => $totalHours,
            ];

            $amounts = [
                'morning_regular' => 0.0,
                'morning_special' => 0.0,
                'evening_regular' => 0.0,
                'evening_special' => 0.0,
                'holiday' => round($totalHours * $effectiveOtHourlyRate, 2),
                'total' => round($totalHours * $effectiveOtHourlyRate, 2),
            ];

            return [
                'hours' => $hoursObj,
                'amounts' => $amounts,
                'meta' => [
                    'is_holiday' => true,
                    'base_hourly_rate' => $baseHourlyRate,
                    'effective_ot_hourly_rate' => $effectiveOtHourlyRate,
                    'ot_multiplier' => (float) ($rateModel?->ot_multiplier ?? 1.5),
                    'holiday_multiplier' => $holidayMultiplier,
                    'total_monthly_hours' => $totalMonthlyHours,
                    'round_up_applied' => $roundUpApplied,
                    'shift_start' => $shiftStart?->toDateTimeString(),
                    'shift_end' => $shiftEnd?->toDateTimeString(),
                    'work_start' => $workStart->toDateTimeString(),
                    'work_end' => $workEndForCalculation->toDateTimeString(),
                ],
            ];
        }


  

        // Respect employee OT permissions
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

        // Threshold logic
        if (!$roundUpApplied && $rateModel && !empty($rateModel->ignore_hours_threshold)) {
            $thresholdConfig = $rateModel->ignore_hours_threshold;
            $thHours = (float) ($thresholdConfig['hours'] ?? 0);
            $thMinutes = (float) ($thresholdConfig['minutes'] ?? 0);
            $thresholdMinutes = (int) round(($thHours * 60) + $thMinutes);

            if ($thresholdMinutes > 0) {
                $applyChunkedThreshold = function (float $regular, float $special) use ($thresholdMinutes): array {
                    $totalHours = $regular + $special;

                    if ($totalHours <= 0) {
                        return [0.0, 0.0];
                    }

                    $totalMinutes = (int) round($totalHours * 60);
                    $chunks = intdiv($totalMinutes, $thresholdMinutes);
                    $allowedMinutes = $chunks * $thresholdMinutes;

                    if ($allowedMinutes <= 0) {
                        return [0.0, 0.0];
                    }

                    $regMinutesRaw = $regular * 60;
                    $specMinutesRaw = $special * 60;
                    $sumRaw = max(1.0, $regMinutesRaw + $specMinutesRaw);

                    $regMinutes = (int) round(($regMinutesRaw / $sumRaw) * $allowedMinutes);
                    $specMinutes = $allowedMinutes - $regMinutes;

                    return [
                        round($regMinutes / 60, 2),
                        round($specMinutes / 60, 2),
                    ];
                };

                [$hours['morning_regular'], $hours['morning_special']] =
                    $applyChunkedThreshold($hours['morning_regular'], $hours['morning_special']);

                [$hours['evening_regular'], $hours['evening_special']] =
                    $applyChunkedThreshold($hours['evening_regular'], $hours['evening_special']);
            }
        }

        // Safety clamp: OT cannot exceed actual possible time before/after shift
        $hours = $this->applySafetyClamp($hours, $workStart, $workEndForCalculation, $shiftStart, $shiftEnd);

        $hours = array_map(fn ($v) => round((float) $v, 2), $hours);
        $hours['total'] = round(
            $hours['morning_regular']
                + $hours['morning_special']
                + $hours['evening_regular']
                + $hours['evening_special'],
            2
        );

        $basicSalary = (float) ($compensation?->basic_salary ?? 0);
        $shiftHoursPerDay = (float) ($rateModel?->shift_hours_per_day ?? 0);
        $workingDaysPerMonth = (float) ($rateModel?->working_days_per_month ?? 0);
        $totalMonthlyHours = $shiftHoursPerDay * $workingDaysPerMonth;
        $baseHourlyRate = $totalMonthlyHours > 0
            ? round($basicSalary / $totalMonthlyHours, 6)
            : 0.0;

        $otMultiplier = (float) ($rateModel?->ot_multiplier ?? 1.5);
        $holidayMultiplier = (float) ($rateModel?->holiday_multiplier ?? 2.0);
        $effectiveMultiplier = $isHoliday ? $holidayMultiplier : $otMultiplier;
        $effectiveOtHourlyRate = round($baseHourlyRate * $effectiveMultiplier, 6);

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
                'round_up_applied' => $roundUpApplied,
                'shift_start' => $shiftStart?->toDateTimeString(),
                'shift_end' => $shiftEnd?->toDateTimeString(),
                'work_start' => $workStart->toDateTimeString(),
                'work_end' => $workEndForCalculation->toDateTimeString(),
            ],
        ];
    }




    private function checkAndApplyRoundUp(
        Carbon $workEnd,
        ?array $morningWindow,
        ?array $eveningWindow,
        ?ShiftOvertimeRate $rateModel
    ): Carbon {
        if (!$rateModel || empty($rateModel->ignore_hours_threshold)) {
            return $workEnd->copy();
        }

        $thresholdConfig = $rateModel->ignore_hours_threshold;
        $thHours = (float) ($thresholdConfig['hours'] ?? 0);
        $thMinutes = (float) ($thresholdConfig['minutes'] ?? 0);
        $thresholdMinutes = (int) round(($thHours * 60) + $thMinutes);

        if ($thresholdMinutes <= 0) {
            return $workEnd->copy();
        }

        $halfThresholdMinutes = $thresholdMinutes / 2;

        if ($eveningWindow) {
            [, $eveningEnd] = $eveningWindow;

            if ($eveningEnd) {
                $rounded = $this->shouldRoundUpToWindowEnd($workEnd, $eveningEnd, $halfThresholdMinutes);
                if ($rounded !== null) {
                    return $rounded;
                }
            }
        }

        if ($morningWindow) {
            [, $morningEnd] = $morningWindow;

            if ($morningEnd) {
                $rounded = $this->shouldRoundUpToWindowEnd($workEnd, $morningEnd, $halfThresholdMinutes);
                if ($rounded !== null) {
                    return $rounded;
                }
            }
        }

        return $workEnd->copy();
    }

    private function shouldRoundUpToWindowEnd(
        Carbon $workEnd,
        Carbon $windowEnd,
        float $halfThresholdMinutes
    ): ?Carbon {
        if ($workEnd->greaterThanOrEqualTo($windowEnd)) {
            return null;
        }

        $diffMinutes = $workEnd->diffInMinutes($windowEnd, false);

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
        if (!$shiftStart || !$shift->morning_ot_start || !$shift->morning_ot_end) {
            return null;
        }

        $start = Carbon::parse($shiftStart->format('Y-m-d') . ' ' . $shift->morning_ot_start);
        $end = Carbon::parse($shiftStart->format('Y-m-d') . ' ' . $shift->morning_ot_end);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        // Morning OT cannot pass actual shift start
        if ($end->greaterThan($shiftStart)) {
            $end = $shiftStart->copy();
        }

        if ($end->lessThanOrEqualTo($start)) {
            return null;
        }

        return [$start, $end];
    }

    private function buildEveningRegularWindow(
        shifts $shift,
        ?Carbon $shiftStart,
        ?Carbon $shiftEnd
    ): ?array {
        if (!$shiftEnd || !$shift->night_ot_start || !$shift->night_ot_end) {
            return null;
        }

        $start = Carbon::parse($shiftEnd->format('Y-m-d') . ' ' . $shift->night_ot_start);
        $end = Carbon::parse($shiftEnd->format('Y-m-d') . ' ' . $shift->night_ot_end);

        // If night OT start time is before shift end time, move to next day
        while ($start->lessThan($shiftEnd)) {
            $start->addDay();
        }

        while ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        // Evening OT cannot start before actual shift end
        if ($start->lessThan($shiftEnd)) {
            $start = $shiftEnd->copy();
        }

        if ($end->lessThanOrEqualTo($start)) {
            return null;
        }

        return [$start, $end];
    }

    private function buildMorningSpecialWindow(
        shifts $shift,
        ?array $regularWindow,
        bool $specialEnabled
    ): ?array {
        if (
            !$specialEnabled ||
            !$regularWindow ||
            !($shift->morning_ot_start && $shift->morning_ot_end)
        ) {
            return null;
        }

        [$regularStart] = $regularWindow;

        $start = $regularStart->copy()->subHours(12);
        $end = $regularStart->copy();

        return [$start, $end];
    }

    private function buildEveningSpecialWindow(
        shifts $shift,
        ?array $regularWindow,
        bool $specialEnabled
    ): ?array {
        if (
            !$specialEnabled ||
            !$regularWindow ||
            !($shift->night_ot_start && $shift->night_ot_end)
        ) {
            return null;
        }

        [, $regularEnd] = $regularWindow;

        $specialStart = $regularEnd->copy();
        $specialEnd = $specialStart->copy()->addHours(12);

        return [$specialStart, $specialEnd];
    }

    private function applySafetyClamp(
        array $hours,
        Carbon $workStart,
        Carbon $workEnd,
        ?Carbon $shiftStart,
        ?Carbon $shiftEnd
    ): array {
        if (!$shiftStart || !$shiftEnd) {
            return $hours;
        }

        $maxMorningPossible = 0.0;
        if ($workStart->lt($shiftStart)) {
            $exactMins = $workStart->diffInMinutes($shiftStart);
            // ආසන්න පැය භාගයට හදනවා (Safety limit එකත්)
            $maxMorningPossible = round((floor($exactMins / 30) * 30) / 60, 2);
        }

        $maxEveningPossible = 0.0;
        if ($workEnd->gt($shiftEnd)) {
            $exactMins = $shiftEnd->diffInMinutes($workEnd);
            // ආසන්න පැය භාගයට හදනවා (Safety limit එකත්)
            $maxEveningPossible = round((floor($exactMins / 30) * 30) / 60, 2);
        }

        $morningTotal = (float) $hours['morning_regular'] + (float) $hours['morning_special'];
        if ($morningTotal > $maxMorningPossible) {
            if ($maxMorningPossible <= 0) {
                $hours['morning_regular'] = 0.0;
                $hours['morning_special'] = 0.0;
            } else {
                $ratio = $maxMorningPossible / max($morningTotal, 0.01);
                $hours['morning_regular'] = round($hours['morning_regular'] * $ratio, 2);
                $hours['morning_special'] = round($hours['morning_special'] * $ratio, 2);
            }
        }

        $eveningTotal = (float) $hours['evening_regular'] + (float) $hours['evening_special'];
        if ($eveningTotal > $maxEveningPossible) {
            if ($maxEveningPossible <= 0) {
                $hours['evening_regular'] = 0.0;
                $hours['evening_special'] = 0.0;
            } else {
                $ratio = $maxEveningPossible / max($eveningTotal, 0.01);
                $hours['evening_regular'] = round($hours['evening_regular'] * $ratio, 2);
                $hours['evening_special'] = round($hours['evening_special'] * $ratio, 2);
            }
        }

        return $hours;
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

        // මුළු විනාඩි ගණන ගන්නවා
        $exactMinutes = $start->diffInMinutes($end);

        // ආසන්න පැය භාගයට රවුණ්ඩ් කරනවා (Floor)
        // උදා: 10 mins -> 0 mins
        // උදා: 45 mins -> 30 mins
        // උදා: 153 mins (2h 33m) -> 150 mins (2.5h)
        $roundedMinutes = floor($exactMinutes / 30) * 30;

        return round($roundedMinutes / 60, 2);
    }
}


*/

