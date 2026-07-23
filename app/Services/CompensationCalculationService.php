<?php

namespace App\Services;

class CompensationCalculationService
{
    public function calculateCompensationSummary(float $basicSalary, float $monthlyBonus, float $sportsFundPercentage, float $staffFundAmount): array
    {
        $totalSalary = round($basicSalary + $monthlyBonus, 2);
        $sportsFundAmount = round($totalSalary * ($sportsFundPercentage / 100), 2);
        $staffFundAmount = round($staffFundAmount, 2);
        $remainingTotalSalary = round($totalSalary - $sportsFundAmount - $staffFundAmount, 2);

        return [
            'total_salary' => $totalSalary,
            'sports_fund_amount' => $sportsFundAmount,
            'staff_fund_amount' => $staffFundAmount,
            'remaining_total_salary' => $remainingTotalSalary,
        ];
    }
}
