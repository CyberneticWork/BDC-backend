<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_wise_deductions', function (Blueprint $table) {
            $table->id();
            $table->string('deduction_code')->unique();
            $table->string('deduction_name');
            $table->text('deduction_description')->nullable();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->date('date');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('employee_wise_bonuses', function (Blueprint $table) {
            $table->id();
            $table->string('bonus_code')->unique();
            $table->string('bonus_name');
            $table->text('bonus_description')->nullable();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->date('date');
            $table->boolean('is_annual')->default(false);
            $table->json('payment_months')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_wise_bonuses');
        Schema::dropIfExists('employee_wise_deductions');
    }
};
