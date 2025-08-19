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
        Schema::create('deductions', function (Blueprint $table) {
            $table->id();
            $table->string('deduction_code')->unique();
            $table->string('deduction_name');

            $table->foreignId('department_id')->nullable()->constrained('departments');
            $table->foreignId('company_id')->constrained('companies');  // Add this line

            $table->text('description')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('category', ['EPF', 'ETF', 'other'])->nullable()->default('other');
            $table->enum('deduction_type', ['fixed', 'variable'])->default('fixed');
            $table->string('startDate')->nullable();
            $table->string('endDate')->nullable();

            $table->softDeletes();
            $table->timestamps();
            $table->index('deduction_code');
            $table->index('deduction_name');
            $table->index('department_id');
            $table->index('company_id');  // Add this index
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deductions');
    }
};
