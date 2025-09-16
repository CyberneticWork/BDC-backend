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
        Schema::table('kpi_task_assignments', function (Blueprint $table) {
            // Add foreign key for company_id if it doesn't exist
            if (!Schema::hasColumn('kpi_task_assignments', 'company_id')) {
                $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            } else {
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            }

            // Add foreign key for department_id if it doesn't exist
            if (!Schema::hasColumn('kpi_task_assignments', 'department_id')) {
                $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            } else {
                $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            }

            // Add foreign key for employee_id if it doesn't exist
            if (!Schema::hasColumn('kpi_task_assignments', 'employee_id')) {
                $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            } else {
                $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kpi_task_assignments', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['department_id']);
            $table->dropForeign(['employee_id']);
        });
    }
};
