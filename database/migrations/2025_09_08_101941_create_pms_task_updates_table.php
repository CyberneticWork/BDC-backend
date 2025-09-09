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
        Schema::create('pms_task_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('pms_kpi_tasks')->onDelete('cascade');

            $table->string('employee_id');
            $table->foreign('employee_id')
                  ->references('attendance_employee_no')
                  ->on('employees')
                  ->cascadeOnDelete();

            $table->text('note')->nullable();
            $table->string('author')->nullable();
            $table->string('document_name')->nullable();
            $table->string('document_size', 50)->nullable();
            $table->string('document_type', 100)->nullable();
            $table->string('document_path')->nullable();
            $table->integer('progress_percentage')->default(0);
            $table->json('performance_metrics')->nullable();
            $table->timestamp('date')->useCurrent();
            $table->timestamps();

            $table->index('task_id');
            $table->index('employee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pms_task_updates');
    }
};
