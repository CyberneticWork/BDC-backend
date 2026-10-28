<?php

namespace Tests\Feature;

use App\Http\Controllers\OffDayHolidayManagementController;
use App\Models\company;
use App\Models\employee;
use Illuminate\Http\Request;
use Tests\TestCase;

class OffDayProcessingTest extends TestCase
{
    public function test_off_day_management_page_and_processing_on_off_behavior(): void
    {
        $hasColumn = \Illuminate\Support\Facades\Schema::hasColumn('compensation', 'off_day_processing');
        $this->assertTrue($hasColumn, 'compensation table must have off_day_processing column');

        $company = company::query()->first();
        if (!$company) {
            $this->markTestSkipped('No company available in test database.');
        }

        $cfg = $company->process_config ?? [];
        $cfg['stamp_duty_paye_holiday'] = ['enabled' => true];
        $company->process_config = $cfg;
        $company->save();

        $emp = employee::query()
            ->whereHas('organizationAssignment', fn($q) => $q->where('company_id', $company->id))
            ->whereHas('compensation')
            ->first();

        if (!$emp) {
            $this->markTestSkipped('No employee with compensation found for company.');
        }

        $comp = $emp->compensation;
        $originalOffDay = $comp->off_day_processing;
        $originalHoliday = $comp->holiday_processing;

        try {
            // Scenario A: off_day_processing = true, holiday_processing = false
            $comp->off_day_processing = true;
            $comp->holiday_processing = false;
            $comp->save();

            $controller = new OffDayHolidayManagementController();
            $request = Request::create('/api/off-day-holiday', 'GET', [
                'company_id' => $company->id,
                'salary_month' => 10,
                'salary_year' => 2026,
                'employee_no' => $emp->attendance_employee_no,
            ]);

            $response = $controller->index($request);
            $this->assertEquals(200, $response->getStatusCode());
            $data = $response->getData()->data;

            $this->assertNotEmpty($data, 'Employee with off_day_processing=true must appear on Off-Day + Holiday Management page');
            $empRow = $data[0];
            $this->assertTrue($empRow->off_day_processing, 'off_day_processing must be true');
            $this->assertTrue($empRow->off_day_processing_active, 'off_day_processing_active must be true');
            $this->assertFalse($empRow->holiday_processing_active, 'holiday_processing_active must be false');
            $this->assertEquals(0, $empRow->holiday_count, 'holiday_count must be 0 when holiday_processing is false');
            $this->assertEquals(0.0, (float)$empRow->holiday_amount, 'holiday_amount must be 0 when holiday_processing is false');

            // Scenario B: off_day_processing = false, holiday_processing = true
            $comp->off_day_processing = false;
            $comp->holiday_processing = true;
            $comp->save();

            $responseB = $controller->index($request);
            $this->assertEquals(200, $responseB->getStatusCode());
            $dataB = $responseB->getData()->data;

            $this->assertNotEmpty($dataB, 'Employee with holiday_processing=true appears on page');
            $empRowB = $dataB[0];
            $this->assertFalse($empRowB->off_day_processing, 'off_day_processing must be false');
            $this->assertFalse($empRowB->off_day_processing_active, 'off_day_processing_active must be false');
            $this->assertEquals(0, $empRowB->off_day_count, 'off_day_count must be 0 when off_day_processing is false');
            $this->assertEquals(0.0, (float)$empRowB->off_day_amount, 'off_day_amount must be 0 when off_day_processing is false');

            // Scenario C: both OFF
            $comp->off_day_processing = false;
            $comp->holiday_processing = false;
            $comp->save();

            $responseC = $controller->index($request);
            $this->assertEquals(200, $responseC->getStatusCode());
            $dataC = $responseC->getData()->data;
            $this->assertEmpty($dataC, 'Employee with both off_day_processing and holiday_processing OFF must not appear in applicable off-day processing list');

        } finally {
            $comp->off_day_processing = $originalOffDay;
            $comp->holiday_processing = $originalHoliday;
            $comp->save();
        }
    }

    public function test_salary_process_controller_off_day_calculation(): void
    {
        $company = company::query()->first();
        if (!$company) {
            $this->markTestSkipped('No company available in test database.');
        }

        $emp = employee::query()
            ->whereHas('organizationAssignment', fn($q) => $q->where('company_id', $company->id))
            ->whereHas('compensation')
            ->first();

        if (!$emp) {
            $this->markTestSkipped('No employee with compensation found for company.');
        }

        $comp = $emp->compensation;
        $originalOffDay = $comp->off_day_processing;

        try {
            // Test 1: off_day_processing = false
            $comp->off_day_processing = false;
            $comp->save();

            $salController = new \App\Http\Controllers\SalaryProcessController();
            $req = Request::create('/api/salary-process/employees', 'GET', [
                'company_id' => $company->id,
                'month' => 10,
                'year' => 2026,
                'search' => $emp->attendance_employee_no,
            ]);

            $res = $salController->getEmployeesByMonthAndCompany($req);
            $this->assertEquals(200, $res->getStatusCode());
            $records = $res->getData()->data;
            $this->assertNotEmpty($records);

            $rec = $records[0];
            $sb = (array) ($rec->salary_breakdown ?? []);
            if (!empty($sb['is_jaysea_process'])) {
                $this->assertEquals(0, (int) ($sb['off_day_count'] ?? 0));
                $this->assertEquals(0.0, (float) ($sb['off_day_amount'] ?? 0));
            }
        } finally {
            $comp->off_day_processing = $originalOffDay;
            $comp->save();
        }
    }
}
