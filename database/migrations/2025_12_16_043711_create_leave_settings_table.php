<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('employee_type', ['probation', 'permanent']);
            $table->integer('annual_leave_days')->nullable(); // For probation
            $table->integer('number_of_quarters')->nullable(); // For permanent
            $table->json('quarters')->nullable(); // Store quarter-wise leave days
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->unique('employee_type');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_settings');
    }
};