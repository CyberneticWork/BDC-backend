<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('paye_tax_tables')) {
            $officialSlabs = [
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

            DB::table('paye_tax_tables')
                ->where('code', 'SL_APIT_STD_150K')
                ->update([
                    'name' => 'Sri Lanka Inland Revenue APIT / PAYE Standard Table (150K Relief)',
                    'personal_relief_monthly' => 150000.00,
                    'personal_relief_annual' => 1800000.00,
                    'slabs' => json_encode($officialSlabs),
                    'is_active' => true,
                    'description' => 'Sri Lanka Inland Revenue Department official APIT Table 1 (Deduction from Regular Monthly Profits) with Rs. 150,000 monthly tax relief and progressive shortcut formulas.',
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op rollback
    }
};
