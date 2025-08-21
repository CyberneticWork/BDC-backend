<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('salary_processes', function (Blueprint $table) {
            $table->id();

            $table->integer('employee_id');
            $table->string('employee_no');
            $table->string('full_name');
            $table->string('company_name');
            $table->string('department_name')->nullable();
            $table->string('sub_department_name')->nullable();
            $table->decimal('basic_salary', 12, 2);
            $table->boolean('increment_active')->default(false);
            $table->string('increment_value')->nullable();
            $table->date('increment_effected_date')->nullable();
            $table->decimal('ot_morning', 10, 2)->nullable()->default(0);
            $table->decimal('ot_evening', 10, 2)->nullable()->default(0);
            $table->boolean('enable_epf_etf')->default(false);
            $table->boolean('br1')->default(false);
            $table->boolean('br2')->default(false);
            $table->string('br_status');
            $table->boolean('stamp')->nullable()->default(false);
            $table->decimal('total_loan_amount', 12, 2)->default(0);
            $table->integer('installment_count')->nullable();
            $table->decimal('installment_amount', 12, 2)->nullable();
            $table->integer('approved_no_pay_days')->default(0);
            $table->json('allowances')->nullable();
            $table->json('deductions')->nullable();
            $table->json('salary_breakdown')->nullable();
            $table->string('month');
            $table->string('year');

            $table->enum('status', ['pending', 'processed', 'issued', 'hold'])->default('pending');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_processes');
    }
};
