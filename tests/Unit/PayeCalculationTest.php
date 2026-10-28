<?php

namespace Tests\Unit;

use App\Models\PayeTaxTable;
use Tests\TestCase;

class PayeCalculationTest extends TestCase
{
    private function getOfficialTaxTable(): PayeTaxTable
    {
        $table = new PayeTaxTable();
        $table->name = 'Sri Lanka Inland Revenue APIT / PAYE Standard Table (150K Relief)';
        $table->code = 'SL_APIT_STD_150K';
        $table->personal_relief_monthly = 150000.00;
        $table->personal_relief_annual = 1800000.00;
        $table->is_active = true;
        $table->slabs = [
            [
                'band' => 1,
                'min_monthly' => 150000.00,
                'max_monthly' => 233333.00,
                'threshold' => 83333.00,
                'rate' => 6,
                'fixed_deduction' => 9000.00,
                'range_display' => 'Exceeding Rs. 150,000 up to Rs. 233,333',
                'formula' => '6% × monthly taxable regular profits − Rs. 9,000',
            ],
            [
                'band' => 2,
                'min_monthly' => 233333.00,
                'max_monthly' => 275000.00,
                'threshold' => 41667.00,
                'rate' => 18,
                'fixed_deduction' => 37000.00,
                'range_display' => 'Exceeding Rs. 233,333 up to Rs. 275,000',
                'formula' => '18% × monthly taxable regular profits − Rs. 37,000',
            ],
            [
                'band' => 3,
                'min_monthly' => 275000.00,
                'max_monthly' => 316667.00,
                'threshold' => 41667.00,
                'rate' => 24,
                'fixed_deduction' => 53500.00,
                'range_display' => 'Exceeding Rs. 275,000 up to Rs. 316,667',
                'formula' => '24% × monthly taxable regular profits − Rs. 53,500',
            ],
            [
                'band' => 4,
                'min_monthly' => 316667.00,
                'max_monthly' => 358333.00,
                'threshold' => 41667.00,
                'rate' => 30,
                'fixed_deduction' => 72500.00,
                'range_display' => 'Exceeding Rs. 316,667 up to Rs. 358,333',
                'formula' => '30% × monthly taxable regular profits − Rs. 72,500',
            ],
            [
                'band' => 5,
                'min_monthly' => 358333.00,
                'max_monthly' => null,
                'threshold' => null,
                'rate' => 36,
                'fixed_deduction' => 94000.00,
                'range_display' => 'Exceeding Rs. 358,333',
                'formula' => '36% × monthly taxable regular profits − Rs. 94,000',
            ],
        ];
        return $table;
    }

    /**
     * Test relief threshold (up to Rs. 150,000)
     */
    public function test_personal_relief_is_nil_tax(): void
    {
        $table = $this->getOfficialTaxTable();
        $res = PayeTaxTable::calculatePaye(150000.00, null, $table);
        $this->assertEquals(0.00, $res['paye_amount']);
        $this->assertEquals('Tax relief – Nil', $res['applicable_formula']);

        $resBelow = PayeTaxTable::calculatePaye(120000.00, null, $table);
        $this->assertEquals(0.00, $resBelow['paye_amount']);
    }

    /**
     * Test Band 1: Exceeding Rs. 150,000 up to Rs. 233,333 (6% - 9,000)
     */
    public function test_band_one_calculation(): void
    {
        $table = $this->getOfficialTaxTable();
        // For Rs. 180,000: (180,000 * 0.06) - 9,000 = 10,800 - 9,000 = 1,800.00
        $res = PayeTaxTable::calculatePaye(180000.00, null, $table);
        $this->assertEquals(1800.00, $res['paye_amount']);
        $this->assertEquals(6, $res['applicable_rate']);

        // For Rs. 233,333: (233,333 * 0.06) - 9,000 = 13,999.98 - 9,000 = 4,999.98
        $resMax = PayeTaxTable::calculatePaye(233333.00, null, $table);
        $this->assertEquals(4999.98, $resMax['paye_amount']);
    }

    /**
     * Test Band 2: Exceeding Rs. 233,333 up to Rs. 275,000 (18% - 37,000)
     * Test case for employee 254 (Balapitiya Modarage Viraj Prasanna Wijayasri)
     */
    public function test_band_two_employee_254_calculation(): void
    {
        $table = $this->getOfficialTaxTable();
        // For Rs. 258,500: (258,500 * 0.18) - 37,000 = 46,530 - 37,000 = 9,530.00
        $res = PayeTaxTable::calculatePaye(258500.00, null, $table);
        $this->assertEquals(9530.00, $res['paye_amount']);
        $this->assertEquals(18, $res['applicable_rate']);
        $this->assertEquals(37000.00, $res['fixed_deduction']);
        $this->assertEquals('Exceeding Rs. 233,333 up to Rs. 275,000', $res['applicable_range']);
        $this->assertEquals('18% × monthly taxable regular profits − Rs. 37,000', $res['applicable_formula']);
    }

    /**
     * Test Band 3: Exceeding Rs. 275,000 up to Rs. 316,667 (24% - 53,500)
     */
    public function test_band_three_calculation(): void
    {
        $table = $this->getOfficialTaxTable();
        // For Rs. 300,000: (300,000 * 0.24) - 53,500 = 72,000 - 53,500 = 18,500.00
        $res = PayeTaxTable::calculatePaye(300000.00, null, $table);
        $this->assertEquals(18500.00, $res['paye_amount']);
        $this->assertEquals(24, $res['applicable_rate']);
    }

    /**
     * Test Band 4: Exceeding Rs. 316,667 up to Rs. 358,333 (30% - 72,500)
     */
    public function test_band_four_calculation(): void
    {
        $table = $this->getOfficialTaxTable();
        // For Rs. 350,000: (350,000 * 0.30) - 72,500 = 105,000 - 72,500 = 32,500.00
        $res = PayeTaxTable::calculatePaye(350000.00, null, $table);
        $this->assertEquals(32500.00, $res['paye_amount']);
        $this->assertEquals(30, $res['applicable_rate']);
    }

    /**
     * Test Band 5: Exceeding Rs. 358,333 (36% - 94,000)
     */
    public function test_band_five_calculation(): void
    {
        $table = $this->getOfficialTaxTable();
        // For Rs. 400,000: (400,000 * 0.36) - 94,000 = 144,000 - 94,000 = 50,000.00
        $res = PayeTaxTable::calculatePaye(400000.00, null, $table);
        $this->assertEquals(50000.00, $res['paye_amount']);
        $this->assertEquals(36, $res['applicable_rate']);
    }

    /**
     * Test Range Table contains all 6 official bands
     */
    public function test_range_table_structure(): void
    {
        $table = $this->getOfficialTaxTable();
        $rangeTable = $table->getRangeTable();

        $this->assertCount(6, $rangeTable);
        $this->assertEquals('Up to Rs. 150,000', $rangeTable[0]['range_display']);
        $this->assertEquals('Tax relief – Nil', $rangeTable[0]['calculation_display']);

        $this->assertEquals('Exceeding Rs. 150,000 up to Rs. 233,333', $rangeTable[1]['range_display']);
        $this->assertEquals('6% × monthly taxable regular profits − Rs. 9,000', $rangeTable[1]['calculation_display']);

        $this->assertEquals('Exceeding Rs. 233,333 up to Rs. 275,000', $rangeTable[2]['range_display']);
        $this->assertEquals('18% × monthly taxable regular profits − Rs. 37,000', $rangeTable[2]['calculation_display']);

        $this->assertEquals('Exceeding Rs. 275,000 up to Rs. 316,667', $rangeTable[3]['range_display']);
        $this->assertEquals('24% × monthly taxable regular profits − Rs. 53,500', $rangeTable[3]['calculation_display']);

        $this->assertEquals('Exceeding Rs. 316,667 up to Rs. 358,333', $rangeTable[4]['range_display']);
        $this->assertEquals('30% × monthly taxable regular profits − Rs. 72,500', $rangeTable[4]['calculation_display']);

        $this->assertEquals('Exceeding Rs. 358,333', $rangeTable[5]['range_display']);
        $this->assertEquals('36% × monthly taxable regular profits − Rs. 94,000', $rangeTable[5]['calculation_display']);
    }

    /**
     * Test dynamic highest band fallback with custom table where min_monthly is omitted
     */
    public function test_dynamic_highest_band_fallback_without_hardcoded_values(): void
    {
        $customTable = new PayeTaxTable();
        $customTable->name = 'Custom 100K Table';
        $customTable->personal_relief_monthly = 100000.00;
        $customTable->slabs = [
            [
                'band' => 1,
                'min_monthly' => 100000.00,
                'max_monthly' => 200000.00,
                'rate' => 10,
                'fixed_deduction' => 10000.00,
            ],
            [
                'band' => 2,
                // Omitted min_monthly and range_display
                'max_monthly' => null,
                'rate' => 20,
                'fixed_deduction' => 30000.00,
            ],
        ];

        // For earnings = 250,000 in custom table: (250,000 * 0.20) - 30,000 = 50,000 - 30,000 = 20,000
        $res = PayeTaxTable::calculatePaye(250000.00, null, $customTable);
        $this->assertEquals(20000.00, $res['paye_amount']);
        $this->assertEquals(2, $res['applicable_band']);
        // Verify min_monthly dynamically used 200,000 without hardcoding
        $this->assertEquals('Exceeding Rs. 200,000', $res['applicable_range']);
    }

    /**
     * Test safe handling when table is missing or inactive
     */
    public function test_safe_handling_when_table_is_null(): void
    {
        $emptyTable = new PayeTaxTable();
        $emptyTable->personal_relief_monthly = 0;
        $emptyTable->slabs = [];

        $res = PayeTaxTable::calculatePaye(200000.00, null, $emptyTable);
        $this->assertEquals(0.00, $res['paye_amount']);
    }
}
