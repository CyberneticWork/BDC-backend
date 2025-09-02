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
        Schema::create('employee_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();

            $table->string('attendance_employee_no')->nullable();
            $table->foreign('attendance_employee_no')->references('attendance_employee_no')->on('employees')->cascadeOnDelete();

            $table->foreignId('deduction_id')->constrained('deductions')->cascadeOnDelete();
            $table->decimal('custom_amount', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);

            $table->unique(['employee_id', 'deduction_id']);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_deductions');
    }
};
