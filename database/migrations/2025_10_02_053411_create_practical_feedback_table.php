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
        Schema::create('practical_feedbacks', function (Blueprint $table) {
            $table->id();
            
            // Employee and assignment references
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('kpi_assignment_id')->nullable()->constrained('kpi_task_assignments')->onDelete('cascade');
            $table->string('task_name')->nullable(); // Store task name for reference
            
            // Email details (simplified)
            $table->string('from_email');
            $table->string('to_email');
            $table->string('subject')->default('Practical Feedback');
            
            // Feedback content
            $table->text('feedback_content');
            
            // Audit fields
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade'); // Who sent the feedback
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for better performance
            $table->index('employee_id');
            $table->index('kpi_assignment_id');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practical_feedbacks');
    }
};
