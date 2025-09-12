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
            $table->foreignId('employee_id');
            $table->foreignId('evaluator_id');
            $table->date('start_date'); // Evaluation period start
            $table->date('end_date'); // Evaluation period end
            $table->integer('percentage'); // Final calculated percentage
            $table->string('grade'); // A+, A, B, etc.
            $table->string('performance_label'); // Excellent, Above Average, etc.
            $table->json('calculation_details'); // Store calculation breakdown as JSON
            $table->integer('task_count'); // Number of tasks evaluated
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_evaluations');
    }
};
