<?php

namespace Tests\Feature;

use App\Models\company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_companies_endpoint_returns_company_code_and_display_label(): void
    {
        company::create([
            'company_code' => 'SPM-S',
            'name' => 'Sample Company',
            'location' => 'Colombo',
            'established' => 2020,
        ]);

        $response = $this->getJson('/api/companies');

        $response->assertOk();
        $response->assertJsonFragment([
            'company_code' => 'SPM-S',
            'company_label' => 'SPM-S - Sample Company',
            'display_name' => 'SPM-S - Sample Company',
        ]);
    }
}
