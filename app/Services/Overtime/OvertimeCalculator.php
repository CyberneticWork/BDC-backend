<?php

namespace App\Services\Overtime;

use App\Models\employee;
use App\Models\shifts;
use App\Models\ShiftOvertimeRate;
use App\Services\CompanyProcessSettings;
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

        if ($workEnd->lessThanOrEqualTo($workStart)) {
            $workEnd->addDay();
        }

        $comp = $employee->compensation;

        // Shift වෙලාවන්
        $shiftStart = Carbon::parse($workStart->format('Y-m-d') . ' ' . $shift->start_time);
        $shiftEnd = Carbon::parse($workStart->format('Y-m-d') . ' ' . $shift->end_time);
        if ($shiftEnd->lte($shiftStart)) $shiftEnd->addDay();

        // OT සීමාවන් (Windows)
        $morningOtLimit = $shift->morning_ot_start ? Carbon::parse($workStart->format('Y-m-d') . ' ' . $shift->morning_ot_start) : null;
        $eveningOtLimit = $shift->night_ot_end ? Carbon::parse($shiftEnd->format('Y-m-d') . ' ' . $shift->night_ot_end) : null;
        if ($eveningOtLimit && $eveningOtLimit->lte($shiftEnd)) $eveningOtLimit->addDay();

        // ---------------------------------------------------------
        // 1. HOLIDAY LOGIC
        // ---------------------------------------------------------
        if ($isHoliday) {
            return $this->calculateHoliday($workStart, $workEnd, $shiftStart, $shiftEnd, $morningOtLimit, $eveningOtLimit, $comp, $employee);
        }

        // ---------------------------------------------------------
        // 2. NORMAL DAY LOG)IC (මෙහිදී කෙලින්ම Compensation Rates ගනු ලැබේ)
        // ---------------------------------------------------------
        $morningHrs = 0.0;
        $eveningHrs = 0.0;

        // Morning OT Calculation (With Limit)
        if ($morningOtLimit && $workStart->lt($shiftStart)) {
            $calcStart = $workStart->max($morningOtLimit);
            if ($calcStart->lt($shiftStart)) {
                $morningHrs = CompanyProcessSettings::otHoursFromMinutes(
                    (int) $calcStart->diffInMinutes($shiftStart),
                    $employee
                );
            }
        }

        // Evening OT Calculation (With Limit)
        if ($eveningOtLimit && $workEnd->gt($shiftEnd)) {
            $calcEnd = $workEnd->min($eveningOtLimit);
            if ($calcEnd->gt($shiftEnd)) {
                $eveningHrs = CompanyProcessSettings::otHoursFromMinutes(
                    (int) $shiftEnd->diffInMinutes($calcEnd),
                    $employee
                );
            }
        }

        // Permissions Check
        if (!($comp?->ot_morning)) $morningHrs = 0.0;
        if (!($comp?->ot_evening)) $eveningHrs = 0.0;

        // 🔥 මෙතැනදී කෙලින්ම රු. 200 සහ රු. 300 අගයන් ලබා ගනී
        $mRate = (float)($comp->ot_morning_rate ?? 0); // උදා: 200
        $eRate = (float)($comp->ot_night_rate ?? 0);   // උදා: 300

        $mAmt = round($morningHrs * $mRate, 2);
        $eAmt = round($eveningHrs * $eRate, 2);

        return [
            'hours' => [
                'morning_regular' => $morningHrs, 'morning_special' => 0.0,
                'evening_regular' => $eveningHrs, 'evening_special' => 0.0,
                'total' => round($morningHrs + $eveningHrs, 2),
            ],
            'amounts' => [
                'morning_regular' => $mAmt,
                'evening_regular' => $eAmt,
                'total' => round($mAmt + $eAmt, 2),
            ]
        ];
    }

    private function calculateHoliday($ws, $we, $ss, $se, $mLimit, $eLimit, $comp, $employee) {
        $finalStart = $mLimit ? $ws->max($mLimit) : $ws;
        $finalEnd = $eLimit ? $we->min($eLimit) : $we;
        if ($finalEnd->lte($finalStart)) return $this->zeroBreakdown();

        $totalHrs = CompanyProcessSettings::otHoursFromMinutes(
            (int) $finalStart->diffInMinutes($finalEnd),
            $employee
        );

        // Holiday සඳහා සාමාන්‍යයෙන් Basic එකෙන් ගණනය වේ (නැතිනම් Compensation Rate එකක් දිය හැක)
        $baseRate = round(($comp?->basic_salary ?? 0) / 240, 6);

        $shiftHrs = 0.0;
        $overlapS = $finalStart->max($ss);
        $overlapE = $finalEnd->min($se);
        if ($overlapS->lt($overlapE)) {
            $shiftHrs = CompanyProcessSettings::otHoursFromMinutes(
                (int) $overlapS->diffInMinutes($overlapE),
                $employee
            );
        }

        $outsideHrs = max(0, $totalHrs - $shiftHrs);

        return [
            'hours' => [
                'morning_regular' => 0, 'evening_regular' => 0,
                'holiday_shift_hours' => $shiftHrs, 'holiday_outside_hours' => $outsideHrs,
                'total' => $totalHrs,
            ],
            'amounts' => [
                'holiday_shift_amount' => round($shiftHrs * ($baseRate * 1.5), 2),
                'holiday_outside_amount' => round($outsideHrs * ($baseRate * 2.0), 2),
                'total' => round(($shiftHrs * $baseRate * 1.5) + ($outsideHrs * $baseRate * 2.0), 2),
            ]
        ];
    }

    private function zeroBreakdown() {
        return ['hours'=>['total'=>0, 'morning_regular'=>0, 'evening_regular'=>0], 'amounts'=>['total'=>0]];
    }
}


/*
namespace App\Services\Overtime;

use App\Models\employee;
use App\Models\shifts;
use App\Models\ShiftOvertimeRate;
use App\Services\CompanyProcessSettings;
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

