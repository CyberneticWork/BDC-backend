<?php

namespace App\Services;

class CompensationCalculationService
{
    public function calculateCompensationSummary(float $basicSalary, float $monthlyBonus, float $sportsFundPercentage, float $staffFundAmount): array
    {
        $sportsFundAmount = round($monthlyBonus * ($sportsFundPercentage / 100), 2);
        $staffFundAmount = round($staffFundAmount, 2);
        $totalSalary = round($basicSalary + $monthlyBonus - ($sportsFundAmount + $staffFundAmount), 2);

        return [
            'sports_fund_amount' => $sportsFundAmount,
            'staff_fund_amount' => $staffFundAmount,
            'total_salary' => $totalSalary,
        ];
    }
}
