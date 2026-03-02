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
        Schema::create('employee_bonuses', function (Blueprint $table) {
            $table->id();

            // Foreign Keys
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('bonus_id');

            // Custom amount (can override default bonus amount)
            $table->decimal('custom_amount', 12, 2)->nullable();

            // Active status
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Prevent duplicate bonus per employee
            $table->unique(['employee_id', 'bonus_id']);

            // Foreign constraints
            $table->foreign('employee_id')
                  ->references('id')
                  ->on('employees')
                  ->onDelete('cascade');

            $table->foreign('bonus_id')
                  ->references('id')
                  ->on('bonuses')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_bonuses');
    }
};