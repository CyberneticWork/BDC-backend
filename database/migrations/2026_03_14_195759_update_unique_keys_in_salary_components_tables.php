<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Foreign Key Rules තාවකාලිකව අක්‍රිය කිරීම (Error එක මඟ හැරීමට)
        Schema::disableForeignKeyConstraints();

        // 1. Employee Bonuses Table
        Schema::table('employee_bonuses', function (Blueprint $table) {
            // මුලින්ම අලුත් නීතිය දානවා (එතකොට Foreign Key එකට ඒක පාවිච්චි කරන්න පුළුවන්)
            $table->unique(['employee_id', 'bonus_id', 'month', 'year'], 'emp_bonus_month_year_unique');
            // ඊට පස්සේ පරණ එක මකනවා
            $table->dropUnique('employee_bonuses_employee_id_bonus_id_unique');
        });

        // 2. Employee Allowances Table
        Schema::table('employee_allowances', function (Blueprint $table) {
            $table->unique(['employee_id', 'allowance_id', 'month', 'year'], 'emp_allow_month_year_unique');
            $table->dropUnique('employee_allowances_employee_id_allowance_id_unique');
        });

        // 3. Employee Deductions Table
        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->unique(['employee_id', 'deduction_id', 'month', 'year'], 'emp_deduct_month_year_unique');
            $table->dropUnique('employee_deductions_employee_id_deduction_id_unique');
        });

        // නැවත Foreign Key Rules සක්‍රිය කිරීම
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('employee_bonuses', function (Blueprint $table) {
            $table->unique(['employee_id', 'bonus_id'], 'employee_bonuses_employee_id_bonus_id_unique');
            $table->dropUnique('emp_bonus_month_year_unique');
        });

        Schema::table('employee_allowances', function (Blueprint $table) {
            $table->unique(['employee_id', 'allowance_id'], 'employee_allowances_employee_id_allowance_id_unique');
            $table->dropUnique('emp_allow_month_year_unique');
        });

        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->unique(['employee_id', 'deduction_id'], 'employee_deductions_employee_id_deduction_id_unique');
            $table->dropUnique('emp_deduct_month_year_unique');
        });

        Schema::enableForeignKeyConstraints();
    }
};