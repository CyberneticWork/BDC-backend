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
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_assignment_id')->nullable()->constrained('kpi_task_assignments');
            // employee_id: reference employees.id, cascade on delete
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            // supervisor_id: reference users.id (nullable, set null when user deleted)
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->onDelete('set null');
            $table->integer('progress')->default(0);
            $table->string('grade')->nullable();
            $table->integer('appraisal_rating')->nullable()->comment('1-5 rating for performance appraisal tasks (1=Poor, 2=Below Average, 3=Average, 4=Above Average, 5=Excellent)');
            $table->text('supervisor_comments')->nullable();
            $table->string('status')->default('Draft'); // Completed, In Progress, Pending Manager, Pending Employee, Draft
            $table->string('review_type'); // performance, progress, quarterly, annual, probation
            $table->string('review_cycle'); // e.g., 2025 Q1, 2025 Annual
            $table->date('start_date');
            $table->date('due_date');
            $table->date('completed_date')->nullable();
            $table->integer('self_reported_progress')->default(0);
            $table->timestamp('self_reported_last_updated')->nullable();
            $table->json('performance_metrics')->nullable(); // Store detailed metrics as JSON
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
    }
};
