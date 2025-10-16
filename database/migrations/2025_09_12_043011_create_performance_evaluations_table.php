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
        Schema::create('performance_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('evaluator_id');
            $table->date('start_date'); // Evaluation period start
            $table->date('end_date'); // Evaluation period end
            $table->integer('percentage'); // Final calculated percentage
            $table->string('grade'); // A+, A, B, etc.
            $table->string('performance_label'); // Excellent, Above Average, etc.
            $table->json('calculation_details'); // Store calculation breakdown as JSON
            $table->integer('task_count'); // Number of tasks evaluated
            $table->timestamps();
            $table->softDeletes(); // Add soft deletes column
        });

        Schema::table('performance_evaluations', function (Blueprint $table) {
            // Add FK for employee_id -> employees.id (cascade on delete)
            // Use explicit index/constraint names to avoid collisions
            $table->foreign('employee_id', 'pe_employee_fk')
                  ->references('id')->on('employees')
                  ->onDelete('cascade');

            // Add FK for evaluator_id -> users.id (cascade on delete)
            $table->foreign('evaluator_id', 'pe_evaluator_fk')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performance_evaluations', function (Blueprint $table) {
            // Drop the foreign keys if they exist
            if (Schema::hasColumn('performance_evaluations', 'evaluator_id')) {
                $table->dropForeign('pe_evaluator_fk');
            }
            if (Schema::hasColumn('performance_evaluations', 'employee_id')) {
                $table->dropForeign('pe_employee_fk');
            }

            $table->dropSoftDeletes(); // Remove soft deletes column
        });

        Schema::dropIfExists('performance_evaluations');
    }
};
