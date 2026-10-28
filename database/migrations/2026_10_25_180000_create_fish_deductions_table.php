<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fish_deductions')) {
            Schema::create('fish_deductions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->decimal('amount', 12, 2);
                $table->date('deduction_date');
                $table->string('applicable_month', 7); // e.g. '2026-10'
                $table->unsignedSmallInteger('salary_year')->index();
                $table->unsignedTinyInteger('salary_month')->index();
                $table->text('description')->nullable();
                $table->string('status', 20)->default('Active'); // Active, Processed, Cancelled
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'salary_year', 'salary_month']);
                $table->index(['employee_id', 'salary_year', 'salary_month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fish_deductions');
    }
};
