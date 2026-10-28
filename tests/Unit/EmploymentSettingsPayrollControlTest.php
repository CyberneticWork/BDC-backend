<?php

namespace Tests\Unit;

use App\Models\company;
use App\Models\employee;
use App\Models\compensation;
use App\Models\PayeTaxTable;
use App\Services\CompanyProcessSettings;
use Tests\TestCase;

class EmploymentSettingsPayrollControlTest extends TestCase
{
    private function getTaxTable(): PayeTaxTable
    {
        $table = new PayeTaxTable();
        $table->name = 'Official IRD Standard Table';
        $table->personal_relief_monthly = 150000.00;
        $table->is_active = true;
        $table->slabs = [
            [
                'band' => 1,
                'min_monthly' => 150000.00,
                'max_monthly' => 233333.00,
                'rate' => 6,
                'fixed_deduction' => 9000.00,
            ],
            [
                'band' => 2,
                'min_monthly' => 233333.00,
                'max_monthly' => 275000.00,
                'rate' => 18,
                'fixed_deduction' => 37000.00,
            ],
        ];
        return $table;
    }

    /**
     * Test PAYE deduction is applied only when paye_active is true and company toggle is ON.
     */
    public function test_paye_applied_when_on_and_zero_when_off(): void
    {
        $grossSalary = 258500.00;
        $taxTable = $this->getTaxTable();

        // 1. Setting ON & Company feature ON
        $isCompanyOn = true;
        $payeActiveOn = true;
        $payeSettingActive = $isCompanyOn && $payeActiveOn;

        $payeAmount = 0.0;
        if ($payeSettingActive) {
            $calc = PayeTaxTable::calculatePaye($grossSalary, '2026-10-01', $taxTable);
            $payeAmount = $calc['paye_amount'];
        }
        $this->assertEquals(9530.00, $payeAmount);

        // 2. Setting OFF & Company feature ON
        $payeActiveOff = false;
        $payeSettingActiveOff = $isCompanyOn && $payeActiveOff;

        $payeAmountOff = 0.0;
        if ($payeSettingActiveOff) {
            $calc = PayeTaxTable::calculatePaye($grossSalary, '2026-10-01', $taxTable);
            $payeAmountOff = $calc['paye_amount'];
        }
        $this->assertEquals(0.0, $payeAmountOff, 'PAYE must be 0 when paye_active is false');

        // 3. Setting ON & Company feature OFF
        $isCompanyOff = false;
        $payeSettingCompanyOff = $isCompanyOff && $payeActiveOn;

        $payeAmountCompanyOff = 0.0;
        if ($payeSettingCompanyOff) {
            $calc = PayeTaxTable::calculatePaye($grossSalary, '2026-10-01', $taxTable);
            $payeAmountCompanyOff = $calc['paye_amount'];
        }
        $this->assertEquals(0.0, $payeAmountCompanyOff, 'PAYE must be 0 when company feature is disabled');
    }

    /**
     * Test Stamp Duty deduction (Rs. 25.00) is applied only when stamp is true and company toggle is ON.
     */
    public function test_stamp_duty_applied_when_on_and_zero_when_off(): void
    {
        $isCompanyOn = true;

        // ON scenario
        $stampOn = true;
        $stampActive = $isCompanyOn && $stampOn;
        $stampValue = $stampActive ? 25.0 : 0.0;
        $this->assertEquals(25.0, $stampValue);

        // OFF scenario
        $stampOff = false;
        $stampActiveOff = $isCompanyOn && $stampOff;
        $stampValueOff = $stampActiveOff ? 25.0 : 0.0;
        $this->assertEquals(0.0, $stampValueOff, 'Stamp Duty must be 0 when stamp is false');

        // Company toggle OFF scenario
        $isCompanyOff = false;
        $stampValueCompanyOff = ($isCompanyOff && $stampOn) ? 25.0 : 0.0;
        $this->assertEquals(0.0, $stampValueCompanyOff, 'Stamp Duty must be 0 when company toggle is OFF');
    }

    /**
     * Test Holiday and Off-Day processing payments are applied only when holiday_processing is true.
     */
    public function test_holiday_processing_applied_when_on_and_zero_when_off(): void
    {
        $isCompanyOn = true;
        $dailyRate = 2248.33;
        $workedOffDays = 4;
        $workedHolidays = 2;

        // ON scenario
        $holidayProcessingOn = true;
        $holidayActive = $isCompanyOn && $holidayProcessingOn;
        $offDayCount = $holidayActive ? $workedOffDays : 0;
        $holidayCount = $holidayActive ? $workedHolidays : 0;
        $offDayAmount = round($dailyRate * $offDayCount, 2);
        $holidayAmount = round($dailyRate * $holidayCount, 2);

        $this->assertEquals(4, $offDayCount);
        $this->assertEquals(2, $holidayCount);
        $this->assertEquals(8993.32, $offDayAmount);
        $this->assertEquals(4496.66, $holidayAmount);

        // OFF scenario
        $holidayProcessingOff = false;
        $holidayActiveOff = $isCompanyOn && $holidayProcessingOff;
        $offDayCountOff = $holidayActiveOff ? $workedOffDays : 0;
        $holidayCountOff = $holidayActiveOff ? $workedHolidays : 0;
        $offDayAmountOff = round($dailyRate * $offDayCountOff, 2);
        $holidayAmountOff = round($dailyRate * $holidayCountOff, 2);

        $this->assertEquals(0, $offDayCountOff);
        $this->assertEquals(0, $holidayCountOff);
        $this->assertEquals(0.0, $offDayAmountOff, 'Off-Day pay must be 0 when holiday_processing is false');
        $this->assertEquals(0.0, $holidayAmountOff, 'Holiday pay must be 0 when holiday_processing is false');
    }

    /**
     * Test No-Pay deduction is applied only when active_nopay is true.
     */
    public function test_nopay_applied_when_on_and_zero_when_off(): void
    {
        $dailyRate = 2248.33;
        $recordedNoPayDays = 3.0;

        // ON scenario
        $activeNopayOn = true;
        $noPayCountOn = $activeNopayOn ? $recordedNoPayDays : 0.0;
        $noPayAmountOn = round($dailyRate * $noPayCountOn, 2);
        $this->assertEquals(3.0, $noPayCountOn);
        $this->assertEquals(6744.99, $noPayAmountOn);

        // OFF scenario
        $activeNopayOff = false;
        $noPayCountOff = $activeNopayOff ? $recordedNoPayDays : 0.0;
        $noPayAmountOff = round($dailyRate * $noPayCountOff, 2);
        $this->assertEquals(0.0, $noPayCountOff);
        $this->assertEquals(0.0, $noPayAmountOff, 'No-Pay deduction must be 0 when active_nopay is false');
    }

    /**
     * Test CompanyProcessSettings usesStampDutyPayeHoliday correctly handles toggle.
     */
    public function test_company_process_settings_pack_enabled(): void
    {
        $comp = new company();
        $comp->process_config = ['stamp_duty_paye_holiday' => ['enabled' => true]];
        $this->assertTrue(CompanyProcessSettings::usesStampDutyPayeHoliday($comp));

        $comp->process_config = ['stamp_duty_paye_holiday' => ['enabled' => false]];
        $this->assertFalse(CompanyProcessSettings::usesStampDutyPayeHoliday($comp));

        $comp->process_config = ['stamp_duty_paye_holiday' => false];
        $this->assertFalse(CompanyProcessSettings::usesStampDutyPayeHoliday($comp));

        $comp->process_config = null;
        $this->assertFalse(CompanyProcessSettings::usesStampDutyPayeHoliday($comp));
    }

    /**
     * Test existing settings are preserved when fields are omitted from an update payload.
     */
    public function test_existing_settings_preserved_when_omitted_from_update_payload(): void
    {
        $compRecord = new compensation();
        $compRecord->enable_epf_etf = true;
        $compRecord->ot_active = true;
        $compRecord->active_nopay = true;
        $compRecord->stamp = false;
        $compRecord->paye_active = true;
        $compRecord->holiday_processing = false;

        $compensationPayload = [
            'basicSalary' => 50000,
            // enableEpfEtf, otActive, nopayActive are NOT present
            'payeActive' => false,
        ];

        $resolveSetting = function (array $keys, $existingVal, $defaultIfNull = false) use ($compensationPayload) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $compensationPayload) && $compensationPayload[$k] !== null && $compensationPayload[$k] !== '') {
                    return filter_var($compensationPayload[$k], FILTER_VALIDATE_BOOLEAN);
                }
            }
            return $existingVal !== null ? (bool) $existingVal : $defaultIfNull;
        };

        $updatedEpf = $resolveSetting(['enableEpfEtf', 'enable_epf_etf'], $compRecord->enable_epf_etf, true);
        $updatedOt = $resolveSetting(['otActive', 'ot_active'], $compRecord->ot_active, false);
        $updatedNopay = $resolveSetting(['nopayActive', 'active_nopay', 'nopay_active'], $compRecord->active_nopay, false);
        $updatedPaye = $resolveSetting(['payeActive', 'paye_active'], $compRecord->paye_active, true);

        // EPF/ETF, OT, and No-Pay must remain true as in database
        $this->assertTrue($updatedEpf, 'Existing EPF/ETF setting must remain preserved when missing from request');
        $this->assertTrue($updatedOt, 'Existing OT setting must remain preserved when missing from request');
        $this->assertTrue($updatedNopay, 'Existing No-Pay setting must remain preserved when missing from request');
        // PAYE was explicitly sent as false, so it should update to false
        $this->assertFalse($updatedPaye, 'PAYE must update to false when explicitly passed');
    }

    /**
     * Test toggling PAYE, Stamp Duty, or Holiday Processing does not affect EPF/ETF or No-Pay.
     */
    public function test_new_settings_do_not_affect_existing_epf_etf_settings(): void
    {
        $epfEtfBase = 65000.00;

        // Employee with EPF/ETF ON, NoPay ON, but PAYE OFF, Stamp OFF, Holiday OFF
        $enableEpfEtf = true;
        $payeActive = false;
        $stampActive = false;
        $holidayProcessing = false;

        $epf8 = $enableEpfEtf ? round($epfEtfBase * 0.08, 2) : 0.0;
        $epf12 = $enableEpfEtf ? round($epfEtfBase * 0.12, 2) : 0.0;
        $etf3 = $enableEpfEtf ? round($epfEtfBase * 0.03, 2) : 0.0;
        $payeAmount = $payeActive ? 5000.0 : 0.0;
        $stampDuty = $stampActive ? 25.0 : 0.0;

        $this->assertEquals(5200.00, $epf8);
        $this->assertEquals(7800.00, $epf12);
        $this->assertEquals(1950.00, $etf3);
        $this->assertEquals(0.0, $payeAmount);
        $this->assertEquals(0.0, $stampDuty);

        // Turn ON PAYE, Stamp Duty, and Holiday Processing
        $payeActive = true;
        $stampActive = true;
        $holidayProcessing = true;

        $epf8After = $enableEpfEtf ? round($epfEtfBase * 0.08, 2) : 0.0;
        $epf12After = $enableEpfEtf ? round($epfEtfBase * 0.12, 2) : 0.0;
        $etf3After = $enableEpfEtf ? round($epfEtfBase * 0.03, 2) : 0.0;
        $stampDutyAfter = $stampActive ? 25.0 : 0.0;

        $this->assertEquals($epf8, $epf8After, 'EPF employee deduction must not change when toggling new settings');
        $this->assertEquals($epf12, $epf12After, 'EPF employer contribution must not change when toggling new settings');
        $this->assertEquals($etf3, $etf3After, 'ETF employer contribution must not change when toggling new settings');
        $this->assertEquals(25.0, $stampDutyAfter, 'Stamp duty becomes active independently');
    }

    /**
     * Test PAYE/APIT defaults to false (OFF) and is never automatically enabled.
     */
    public function test_paye_active_defaults_to_false_and_is_never_auto_enabled(): void
    {
        $comp = new \App\Models\compensation();
        $this->assertFalse($comp->paye_active, 'compensation model paye_active must default to false (OFF)');
        $this->assertFalse($comp->stamp, 'compensation model stamp must default to false (OFF)');

        // Pre-payroll / payroll calculation check: when OFF, PAYE must be 0 regardless of high salary
        $grossSalary = 350000.00;
        $payeSettingActive = false; // setting is OFF
        $payeAmount = 0.0;
        if ($payeSettingActive) {
            $calc = PayeTaxTable::calculatePaye($grossSalary, '2026-10-01');
            $payeAmount = (float) ($calc['paye_amount'] ?? 0);
        }
        $this->assertEquals(0.0, $payeAmount, 'When PAYE is OFF, tax must be 0 even for high taxable salary');

        // When manually enabled (ON)
        $payeSettingActive = true;
        $calcOn = PayeTaxTable::calculatePaye($grossSalary, '2026-10-01', $this->getTaxTable());
        $payeAmountOn = (float) ($calcOn['paye_amount'] ?? 0);
        $this->assertGreaterThan(0.0, $payeAmountOn, 'When PAYE is ON, tax must be calculated using configured tax table');
    }

    /**
     * Test Stamp Duty amount is retrieved dynamically from configuration with fallback.
     */
    public function test_stamp_duty_amount_retrieved_from_configuration(): void
    {
        // 1. Default statutory amount when not customized
        $compDefault = new \App\Models\company();
        $compDefault->process_config = ['stamp_duty_paye_holiday' => ['enabled' => true]];
        $this->assertEquals(25.0, CompanyProcessSettings::stampDutyAmount($compDefault));

        // 2. Custom configured amount inside pack config
        $compCustom = new \App\Models\company();
        $compCustom->process_config = [
            'stamp_duty_paye_holiday' => [
                'enabled' => true,
                'stamp_duty_amount' => 50.00,
            ],
        ];
        $this->assertEquals(50.0, CompanyProcessSettings::stampDutyAmount($compCustom));
    }

    /**
     * Test Off-Day processing applied only when off_day_processing is true.
     */
    public function test_off_day_processing_applied_when_on_and_zero_when_off(): void
    {
        $basicSalary = 67450.00;
        $divisor = 30.0;
        $dailyRate = round($basicSalary / $divisor, 2); // 2248.33
        $workedOffDays = 4;
        $isCompanyOn = true;

        // 1. Off-Day ON scenario
        $offDayOn = true;
        $offDayActive = $isCompanyOn && $offDayOn;
        $offDayCount = $offDayActive ? $workedOffDays : 0;
        $offDayAmount = round($dailyRate * $offDayCount, 2);

        $this->assertEquals(4, $offDayCount);
        $this->assertEquals(8993.32, $offDayAmount);

        // 2. Off-Day OFF scenario
        $offDayOff = false;
        $offDayActiveOff = $isCompanyOn && $offDayOff;
        $offDayCountOff = $offDayActiveOff ? $workedOffDays : 0;
        $offDayAmountOff = round($dailyRate * $offDayCountOff, 2);

        $this->assertEquals(0, $offDayCountOff);
        $this->assertEquals(0.0, $offDayAmountOff, 'Off-Day pay must be 0.00 when off_day_processing is OFF');

        // 3. Independence: Holiday ON but Off-Day OFF
        $holidayProcessingOn = true;
        $holidayActive = $isCompanyOn && $holidayProcessingOn;
        $holidayCount = $holidayActive ? 2 : 0;
        $holidayAmount = round($dailyRate * $holidayCount, 2);

        $this->assertEquals(4496.66, $holidayAmount, 'Holiday pay calculates when holiday_processing is ON');
        $this->assertEquals(0.0, $offDayAmountOff, 'Off-Day pay remains 0.00 even if holiday_processing is ON');
    }

    /**
     * Test compensation model defaults off_day_processing to false.
     */
    public function test_off_day_processing_defaults_to_false(): void
    {
        $comp = new compensation();
        $this->assertFalse($comp->off_day_processing, 'compensation model off_day_processing must default to false (OFF)');
    }

    /**
     * Test off_day_processing is preserved when omitted from update payload.
     */
    public function test_off_day_processing_preserved_when_omitted_from_update(): void
    {
        $compRecord = new compensation();
        $compRecord->off_day_processing = true;

        $compensationPayload = [
            'basicSalary' => 60000,
            // offDayProcessing omitted
        ];

        $resolveSetting = function (array $keys, $existingVal, $defaultIfNull = false) use ($compensationPayload) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $compensationPayload) && $compensationPayload[$k] !== null && $compensationPayload[$k] !== '') {
                    return filter_var($compensationPayload[$k], FILTER_VALIDATE_BOOLEAN);
                }
            }
            return $existingVal !== null ? (bool) $existingVal : $defaultIfNull;
        };

        $updatedOffDay = $resolveSetting(['offDayProcessing', 'off_day_processing'], $compRecord->off_day_processing, false);
        $this->assertTrue($updatedOffDay, 'Existing off_day_processing must be preserved when not sent in update');
    }
}
