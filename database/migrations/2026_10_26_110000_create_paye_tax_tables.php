<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('paye_tax_tables')) {
            Schema::create('paye_tax_tables', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->decimal('personal_relief_monthly', 12, 2)->default(150000.00);
                $table->decimal('personal_relief_annual', 12, 2)->default(1800000.00);
                $table->json('slabs'); // Array of [{ "band": 1, "threshold": 41666.67, "rate": 6 }, ...]
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();
            });

            // Seed default Sri Lanka APIT / PAYE tax table:
            // Relief: Rs. 150,000 / month
            // Bands:
            //   1: next 41,666.67 @ 6%
            //   2: next 41,666.67 @ 12%
            //   3: next 41,666.67 @ 18%
            //   4: next 41,666.67 @ 24%
            //   5: next 41,666.67 @ 30%
            //   6: balance @ 36%
            DB::table('paye_tax_tables')->insert([
                [
                    'name' => 'Sri Lanka Inland Revenue APIT / PAYE Standard Table (150K Relief)',
                    'code' => 'SL_APIT_STD_150K',
                    'effective_from' => '2025-01-01',
                    'effective_to' => null,
                    'personal_relief_monthly' => 150000.00,
                    'personal_relief_annual' => 1800000.00,
                    'slabs' => json_encode([
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
                    ]),
                    'is_active' => true,
                    'description' => 'Sri Lanka Inland Revenue Department official APIT Table 1 (Deduction from Regular Monthly Profits) with Rs. 150,000 monthly tax relief and progressive shortcut formulas.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Sri Lanka Inland Revenue APIT Table (100K Relief)',
                    'code' => 'SL_APIT_PREV_100K',
                    'effective_from' => '2023-01-01',
                    'effective_to' => '2024-12-31',
                    'personal_relief_monthly' => 100000.00,
                    'personal_relief_annual' => 1200000.00,
                    'slabs' => json_encode([
                        ['band' => 1, 'threshold' => 41666.67, 'rate' => 6],
                        ['band' => 2, 'threshold' => 41666.67, 'rate' => 12],
                        ['band' => 3, 'threshold' => 41666.67, 'rate' => 18],
                        ['band' => 4, 'threshold' => 41666.67, 'rate' => 24],
                        ['band' => 5, 'threshold' => 41666.67, 'rate' => 30],
                        ['band' => 6, 'threshold' => null, 'rate' => 36],
                    ]),
                    'is_active' => true,
                    'description' => 'Sri Lanka Inland Revenue Department APIT table with Rs. 100,000 monthly tax-free relief.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paye_tax_tables');
    }
};
