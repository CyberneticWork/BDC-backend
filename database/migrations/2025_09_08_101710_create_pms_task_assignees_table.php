<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pms_task_assignees', function (Blueprint $table) {
            $table->id();
            // store attendance_employee_no (string) in employee_id column
            $table->string('employee_id');
            $table->foreign('employee_id')
                  ->references('attendance_employee_no')
                  ->on('employees')
                  ->cascadeOnDelete();

            $table->foreignId('task_id')->constrained('pms_kpi_tasks')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['task_id', 'employee_id']);
            $table->index('task_id');
            $table->index('employee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pms_task_assignees');
    }
};
