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

       
        /*
         
        if ($isHoliday) {
    $totalHours = round($workStart->floatDiffInHours($workEndForCalculation), 2);

    $basicSalary = (float) ($compensation?->basic_salary ?? 0);
    $shiftHoursPerDay = (float) ($rateModel?->shift_hours_per_day ?? 0);
    $workingDaysPerMonth = (float) ($rateModel?->working_days_per_month ?? 0);
    $totalMonthlyHours = $shiftHoursPerDay * $workingDaysPerMonth;

    $baseHourlyRate = $totalMonthlyHours > 0
        ? round($basicSalary / $totalMonthlyHours, 6)
        : 0.0;

    $holidayMultiplier = (float) ($rateModel?->holiday_multiplier ?? 2.0);
    $effectiveOtHourlyRate = round($baseHourlyRate * $holidayMultiplier, 6);

    $hours = [
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
        'hours' => $hours,
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
*/




if ($isHoliday) {
    $totalHours = round($workStart->floatDiffInHours($workEndForCalculation), 2);

    $basicSalary = (float) ($compensation?->basic_salary ?? 0);
    $shiftHoursPerDay = (float) ($rateModel?->shift_hours_per_day ?? 0);
    $workingDaysPerMonth = (float) ($rateModel?->working_days_per_month ?? 0);
    $totalMonthlyHours = $shiftHoursPerDay * $workingDaysPerMonth;

    $baseHourlyRate = $totalMonthlyHours > 0
        ? round($basicSalary / $totalMonthlyHours, 6)
        : 0.0;

    $holidayMultiplier = (float) ($rateModel?->holiday_multiplier ?? 2.0);
    $effectiveOtHourlyRate = round($baseHourlyRate * $holidayMultiplier, 6);

    $hours = [
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
        'hours' => $hours,
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
            $maxMorningPossible = round($workStart->floatDiffInHours($shiftStart), 2);
        }

        $maxEveningPossible = 0.0;
        if ($workEnd->gt($shiftEnd)) {
            $maxEveningPossible = round($shiftEnd->floatDiffInHours($workEnd), 2);
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

        return round($start->floatDiffInHours($end), 2);
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

        // Build OT windows ONLY from explicit shift config
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

if ($isHoliday) {
    $totalHours = round($workStart->floatDiffInHours($workEndForCalculation), 2);

    $basicSalary = (float) ($compensation?->basic_salary ?? 0);
    $shiftHoursPerDay = (float) ($rateModel?->shift_hours_per_day ?? 0);
    $workingDaysPerMonth = (float) ($rateModel?->working_days_per_month ?? 0);
    $totalMonthlyHours = $shiftHoursPerDay * $workingDaysPerMonth;

    $baseHourlyRate = $totalMonthlyHours > 0
        ? round($basicSalary / $totalMonthlyHours, 6)
        : 0.0;

    $holidayMultiplier = (float) ($rateModel?->holiday_multiplier ?? 2.0);
    $effectiveOtHourlyRate = round($baseHourlyRate * $holidayMultiplier, 6);

    
    $hours = [
        'morning_regular' => $totalHours,
        'morning_special' => 0.0,
        'evening_regular' => 0.0,
        'evening_special' => 0.0,
        'total' => $totalHours,
    ];

    $amounts = [
        'morning_regular' => round($totalHours * $effectiveOtHourlyRate, 2),
        'morning_special' => 0.0,
        'evening_regular' => 0.0,
        'evening_special' => 0.0,
        'total' => round($totalHours * $effectiveOtHourlyRate, 2),
    ];

    return [
        'hours' => $hours,
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

        // Rate calculation
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

    // if end <= start, move end next day
    if ($end->lessThanOrEqualTo($start)) {
        $end->addDay();
    }

    // Morning OT cannot go beyond actual shift start
    if ($end->greaterThan($shiftStart)) {
        $end = $shiftStart->copy();
    }

    // invalid window
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

    // If start is before shift end, move it forward
    while ($start->lessThan($shiftEnd)) {
        $start->addDay();
    }

    while ($end->lessThanOrEqualTo($start)) {
        $end->addDay();
    }

    // Evening OT cannot start before shift end
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

    private function makeWindowFromTimes(
        Carbon $base,
        string $startTime,
        string $endTime,
        bool $allowPast = false
    ): array {
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

    private function buildEveningWindowWithTimes(
        Carbon $base,
        Carbon $shiftEnd,
        string $startTime,
        ?string $endTime
    ): array {
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
            $maxMorningPossible = round($workStart->floatDiffInHours($shiftStart), 2);
        }

        $maxEveningPossible = 0.0;
        if ($workEnd->gt($shiftEnd)) {
            $maxEveningPossible = round($shiftEnd->floatDiffInHours($workEnd), 2);
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

        return round($start->floatDiffInHours($end), 2);
    }
}

*/
