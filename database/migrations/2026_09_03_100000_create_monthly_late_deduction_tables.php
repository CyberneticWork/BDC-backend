<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_late_deduction_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('status', 20)->default('preview'); // preview, applied
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('employee_count')->default(0);
            $table->timestamps();

            $table->index(['year', 'month', 'company_id']);
        });

        Schema::create('monthly_late_deduction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');

            $table->unsignedInteger('total_late_minutes')->default(0);
            $table->unsignedInteger('late_day_count')->default(0);
            $table->unsignedInteger('free_minutes')->default(90);
            $table->unsignedInteger('chargeable_minutes')->default(0);
            $table->unsignedInteger('excess_minutes')->default(0);

            $table->unsignedTinyInteger('short_leave_count')->default(0);
            $table->decimal('short_leave_days', 8, 4)->default(0);
            $table->decimal('annual_leave_days', 8, 4)->default(0);
            $table->decimal('casual_leave_days', 8, 4)->default(0);
            $table->decimal('nopay_days', 8, 4)->default(0);
            $table->unsignedInteger('nopay_minutes')->default(0);

            $table->decimal('annual_balance_before', 8, 4)->default(0);
            $table->decimal('casual_balance_before', 8, 4)->default(0);
            $table->decimal('annual_balance_after', 8, 4)->default(0);
            $table->decimal('casual_balance_after', 8, 4)->default(0);

            $table->string('band', 40)->nullable();
            $table->string('status', 20)->default('pending'); // pending, applied, skipped
            $table->json('breakdown')->nullable();
            $table->json('late_days')->nullable();
            $table->json('created_leave_ids')->nullable();
            $table->json('created_nopay_ids')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'year', 'month'], 'monthly_late_emp_unique');
            $table->index(['year', 'month', 'status']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_late_deduction_items');
        Schema::dropIfExists('monthly_late_deduction_runs');
    }
};
