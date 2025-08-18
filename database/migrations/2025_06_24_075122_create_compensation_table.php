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
        Schema::create('compensation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->decimal('basic_salary', 10, 2);
            $table->string('increment_value')->nullable();
            $table->date('increment_effected_date')->nullable();

            $table->boolean('enable_epf_etf')->nullable()->default(false);
            $table->boolean('ot_active')->nullable()->default(false);
            $table->boolean('early_deduction')->nullable()->default(false);
            $table->boolean('increment_active')->nullable()->default(false);
            $table->boolean('active_nopay')->nullable()->default(false);
            $table->boolean('ot_morning')->nullable()->default(false);
            $table->boolean('ot_evening')->nullable()->default(false);
            $table->decimal('ot_morning_rate', 10, 2)->nullable()->default(0);
            $table->decimal('ot_night_rate', 10, 2)->nullable()->default(0);

            $table->string('bank_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('branch_code')->nullable();
            $table->string('bank_account_no')->nullable();

            $table->boolean('br1')->nullable()->default(false);
            $table->boolean('br2')->nullable()->default(false);

            $table->text('comments')->nullable();
            $table->boolean('secondary_emp')->nullable()->default(false);
            $table->boolean('primary_emp_basic')->nullable()->default(false);
            $table->boolean('stamp')->nullable()->default(false);

            $table->softDeletes();
            $table->timestamps();

            $table->index('employee_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compensation');
    }
};
