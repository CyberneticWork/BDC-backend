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
        Schema::create('performance_appraisals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('appraiser_id'); // The supervisor/appraiser
            $table->date('start_date'); // Appraisal period start
            $table->date('end_date'); // Appraisal period end
            $table->integer('employee_self_rating')->default(0); // Employee's self rating (0-100)
            $table->integer('supervisor_rating')->default(0); // Supervisor's rating (0-100)
            $table->decimal('average_rating', 5, 2)->default(0); // (self + supervisor) / 2
            $table->integer('percentage')->default(0); // Final calculated percentage
            $table->string('grade', 10); // A+, A, B, B-, C
            $table->string('performance_label'); // Excellent, Above Average, etc.
            $table->json('calculation_details'); // Store calculation breakdown as JSON
            $table->integer('task_count')->default(0); // Number of tasks appraised
            $table->text('supervisor_comments')->nullable();
            $table->text('employee_comments')->nullable();
            $table->string('status')->default('Draft'); // Draft, Completed, Pending Review
            $table->timestamps();
            $table->softDeletes();

            // Foreign key constraints
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('appraiser_id')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes
            $table->index(['employee_id', 'start_date', 'end_date']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_appraisals');
    }
};
