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
        Schema::create('pms_performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->nullable()->constrained('pms_kpi_tasks')->onDelete('set null');

            $table->string('employee_id');
            $table->foreign('employee_id')
                  ->references('attendance_employee_no')
                  ->on('employees')
                  ->cascadeOnDelete();

            $table->string('type', 100)->default('Performance Review');
            $table->enum('status', ['Draft', 'In Progress', 'Pending Manager', 'Pending Employee', 'Completed'])->default('Draft');
            $table->date('start_date');
            $table->date('due_date');
            $table->date('completed_date')->nullable();
            $table->string('cycle', 50)->nullable();
            $table->string('grade', 5)->nullable();

            // Keep supervisor_id referencing employees.id (int) unless you also want attendance number
            $table->string('supervisor_id')->nullable();
            $table->foreign('supervisor_id')
                  ->references('attendance_employee_no')
                  ->on('employees')
                  ->onDelete('set null');

            $table->text('supervisor_comments')->nullable();
            $table->integer('supervisor_progress')->default(0);
            $table->foreignId('supervisor_metrics_id')->nullable()->constrained('pms_performance_metrics')->onDelete('set null');
            $table->integer('self_reported_progress')->default(0);
            $table->foreignId('self_reported_metrics_id')->nullable()->constrained('pms_performance_metrics')->onDelete('set null');
            $table->timestamp('supervisor_last_updated')->nullable();
            $table->timestamp('self_reported_last_updated')->nullable();
            $table->string('self_reported_author')->nullable();
            $table->timestamp('last_updated')->nullable()->useCurrent();
            $table->timestamps();

            $table->index('task_id');
            $table->index('employee_id');
            $table->index('supervisor_id');
            $table->index('supervisor_metrics_id');
            $table->index('self_reported_metrics_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pms_performance_reviews');
    }
};
