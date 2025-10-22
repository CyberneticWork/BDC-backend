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
        Schema::create('task_progress_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_assignment_id')->constrained('kpi_task_assignments');
            // store employee attendance number (string) and reference employees.attendance_employee_no
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            // $table->foreign('employee_id')
            //       ->references('attendance_employee_no')
            //       ->on('employees')
            //       ->cascadeOnDelete();
            $table->text('note');
            $table->integer('progress_percentage');
            $table->integer('rating')->nullable()->comment('1-5 rating for performance appraisal tasks');
            $table->json('performance_metrics'); // Store metrics as JSON
            $table->string('document_name')->nullable();
            $table->string('document_size')->nullable();
            $table->string('document_type')->nullable();
            $table->string('document_path')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_progress_submissions');
    }
};
