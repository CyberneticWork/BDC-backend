<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Shop and Office Employees (Regulation of Employment and Remuneration) Act No. 19 of 1954
 * — statutory Annual Leave and Casual Leave entitlements for Sri Lanka.
 *
 * Official Department of Labour / Act summary:
 * - Annual leave: none in join (1st) calendar year; pro-rated in 2nd year by join quarter; 14 days from 3rd year.
 * - Casual leave: 1 day per completed 2 months in 1st year; 7 days from 2nd year onward
 *   (covers private business, ill-health, other reasonable cause — no separate statutory sick leave).
 */
class ShopAndOfficeLeaveCalculator
{
    /**
     * @return array{
     *   employment_year:int,
     *   is_first_year:bool,
     *   is_second_year:bool,
     *   annual_days:float,
     *   casual_days:float,
     *   completed_months_first_year:int,
     *   annual_note:string,
     *   casual_note:string,
     *   law_reference:string
     * }
     */
    public function calculate(Carbon $joinDate, Carbon $asOfDate): array
    {
        $joinDate = $joinDate->copy()->startOfDay();
        $asOfDate = $asOfDate->copy()->startOfDay();

        if ($asOfDate->lt($joinDate)) {
            $asOfDate = $joinDate->copy();
        }

        $joinYear = (int) $joinDate->year;
        $asOfYear = (int) $asOfDate->year;
        $joinMonth = (int) $joinDate->month;

        $employmentYear = max(1, ($asOfYear - $joinYear) + 1);
        $isFirstYear = $asOfYear === $joinYear;
        $isSecondYear = $asOfYear === ($joinYear + 1);

        $annualDays = 0.0;
        $casualDays = 0.0;
        $completedMonths = 0;
        $annualNote = '';
        $casualNote = '';

        if ($isFirstYear) {
            // Act: no annual leave in the first calendar year of employment.
            $annualDays = 0.0;
            $annualNote = 'Shop & Office Act: No Annual Leave in the 1st calendar year of employment.';

            // Act / Labour Dept: 1 casual day for each completed period of 2 months' service
            // (equivalent wording: ½ day per completed month, taken in whole/half days).
            $completedMonths = $this->completedMonthsOfService($joinDate, $asOfDate);
            $casualDays = (float) intdiv($completedMonths, 2);
            $casualNote = "Shop & Office Act (1st year): 1 Casual day per 2 completed months "
                . "({$completedMonths} month(s) completed → {$casualDays} day(s)). "
                . "Casual leave covers private business, ill-health or other reasonable cause.";
        } elseif ($isSecondYear) {
            // Act: 2nd calendar year annual leave depends on join quarter in year 1.
            $annualDays = (float) $this->secondYearAnnualLeave($joinMonth);
            $annualNote = 'Shop & Office Act (2nd year): Annual Leave based on join date in year 1 — '
                . $this->joinQuarterLabel($joinMonth) . " → {$annualDays} day(s).";

            $casualDays = 7.0;
            $casualNote = 'Shop & Office Act (2nd year onward): 7 Casual Leave days per year.';
        } else {
            // Act: 3rd and subsequent calendar years — 14 annual + 7 casual.
            $annualDays = 14.0;
            $annualNote = 'Shop & Office Act (3rd year onward): 14 Annual Leave days '
                . '(not less than 7 consecutive).';

            $casualDays = 7.0;
            $casualNote = 'Shop & Office Act (2nd year onward): 7 Casual Leave days per year.';
        }

        return [
            'employment_year' => $employmentYear,
            'is_first_year' => $isFirstYear,
            'is_second_year' => $isSecondYear,
            'annual_days' => $annualDays,
            'casual_days' => $casualDays,
            'completed_months_first_year' => $completedMonths,
            'annual_note' => $annualNote,
            'casual_note' => $casualNote,
            'law_reference' => 'Shop and Office Employees Act No. 19 of 1954 (Sri Lanka)',
        ];
    }

    /**
     * Whole completed months of continuous service between join and as-of date.
     */
    public function completedMonthsOfService(Carbon $joinDate, Carbon $asOfDate): int
    {
        if ($asOfDate->lte($joinDate)) {
            return 0;
        }

        // Use calendar month difference; do not count a partial trailing month.
        $months = $joinDate->diffInMonths($asOfDate);

        return max(0, (int) $months);
    }

    /**
     * 2nd-year annual leave from join month in the first calendar year.
     */
    public function secondYearAnnualLeave(int $joinMonth): int
    {
        if ($joinMonth >= 1 && $joinMonth <= 3) {
            return 14; // 1 Jan – 31 Mar
        }
        if ($joinMonth >= 4 && $joinMonth <= 6) {
            return 10; // 1 Apr – 30 Jun
        }
        if ($joinMonth >= 7 && $joinMonth <= 9) {
            return 7; // 1 Jul – 30 Sep
        }

        return 4; // 1 Oct – 31 Dec
    }

    private function joinQuarterLabel(int $joinMonth): string
    {
        if ($joinMonth <= 3) {
            return 'joined Jan–Mar';
        }
        if ($joinMonth <= 6) {
            return 'joined Apr–Jun';
        }
        if ($joinMonth <= 9) {
            return 'joined Jul–Sep';
        }

        return 'joined Oct–Dec';
    }
}
