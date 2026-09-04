<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Shop and Office Employees (Regulation of Employment and Remuneration) Act No. 19 of 1954
 * — statutory Annual Leave and Casual Leave entitlements for Sri Lanka.
 *
 * Official Department of Labour / Act summary (Annual unchanged):
 * - Annual leave: none in join (1st) calendar year; pro-rated in 2nd year by join quarter; 14 days from 3rd year.
 *
 * Casual leave (company rule until 3rd year):
 * - 1st and 2nd calendar years: 0.5 day per month from the month after joining (hire month ignored).
 * - 3rd year onward: 7 days per year.
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

        // Casual: 0.5 day per completed month until the 3rd calendar year.
        // Hire month is excluded — accrual starts from the 1st of the following month.
        $useCasualAccrual = $isFirstYear || $isSecondYear;
        if ($useCasualAccrual) {
            $completedMonths = $this->completedMonthsForCasualAccrual($joinDate, $asOfDate);
            $casualDays = round($completedMonths * 0.5, 4);
            $yearLabel = $isFirstYear ? '1st' : '2nd';
            $casualNote = "Casual Leave ({$yearLabel} year): 0.5 day per month from the month after joining "
                . "(hire month ignored; e.g. Jan join → Feb onward) "
                . "({$completedMonths} month(s) → {$casualDays} day(s)). "
                . "This accrual applies until the 3rd year. "
                . "Casual leave covers private business, ill-health or other reasonable cause.";
        } else {
            $casualDays = 7.0;
            $casualNote = 'Casual Leave (3rd year onward): 7 days per year.';
        }

        if ($isFirstYear) {
            // Act: no annual leave in the first calendar year of employment.
            $annualDays = 0.0;
            $annualNote = 'Shop & Office Act: No Annual Leave in the 1st calendar year of employment.';
        } elseif ($isSecondYear) {
            // Act: 2nd calendar year annual leave depends on join quarter in year 1.
            $annualDays = (float) $this->secondYearAnnualLeave($joinMonth);
            $annualNote = 'Shop & Office Act (2nd year): Annual Leave based on join date in year 1 — '
                . $this->joinQuarterLabel($joinMonth) . " → {$annualDays} day(s).";
        } else {
            // Act: 3rd and subsequent calendar years — 14 annual.
            $annualDays = 14.0;
            $annualNote = 'Shop & Office Act (3rd year onward): 14 Annual Leave days '
                . '(not less than 7 consecutive).';
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
     * Months counted for Casual accrual.
     * Hire month is ignored. Counting starts from the next calendar month
     * (e.g. January join → February onward), inclusive of the as-of month.
     */
    public function completedMonthsForCasualAccrual(Carbon $joinDate, Carbon $asOfDate): int
    {
        // First countable month = calendar month AFTER the hire month
        $firstCountable = $joinDate->copy()->startOfMonth()->addMonth()->startOfDay();

        // Still inside hire month → nothing earned yet
        if ($asOfDate->lt($firstCountable)) {
            return 0;
        }

        $from = $firstCountable->copy()->startOfMonth();
        $to = $asOfDate->copy()->startOfMonth();

        // Inclusive: Feb→Feb = 1, Feb→May = 4
        return max(0, (int) $from->diffInMonths($to) + 1);
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
