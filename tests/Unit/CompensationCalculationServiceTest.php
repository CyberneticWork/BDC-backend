<?php

namespace Tests\Unit;

use App\Services\CompensationCalculationService;
use PHPUnit\Framework\TestCase;

class CompensationCalculationServiceTest extends TestCase
{
    public function test_it_calculates_sports_and_staff_funds_from_monthly_bonus():
    {
        $service = new CompensationCalculationService();

        $result = $service->calculateCompensationSummary(50000, 5000, 2.5, 100);

        $this->assertSame(125.0, $result['sports_fund_amount']);
        $this->assertSame(100.0, $result['staff_fund_amount']);
        $this->assertSame(54900.0, $result['total_salary']);
    }
}
