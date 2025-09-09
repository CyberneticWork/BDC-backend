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
        Schema::create('pms_task_creators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('pms_kpi_tasks')->onDelete('cascade');

            $table->string('employee_id')->nullable();
            $table->foreign('employee_id')
                  ->references('attendance_employee_no')
                  ->on('employees')
                  ->nullOnDelete();

            $table->string('name')->nullable();
            $table->string('role', 100)->nullable();
            $table->timestamp('date')->nullable()->useCurrent();
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
        Schema::dropIfExists('pms_task_creators');
    }
};
