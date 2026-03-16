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
        // 1. Allowances table එකට එකතු කිරීම
        Schema::table('employee_allowances', function (Blueprint $table) {
            $table->integer('month')->nullable()->after('allowance_id');
            $table->integer('year')->nullable()->after('month');
        });

        // 2. Deductions table එකට එකතු කිරීම
        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->integer('month')->nullable()->after('deduction_id');
            $table->integer('year')->nullable()->after('month');
        });

        // 3. Bonuses table එකට එකතු කිරීම
        Schema::table('employee_bonuses', function (Blueprint $table) {
            $table->integer('month')->nullable()->after('bonus_id');
            $table->integer('year')->nullable()->after('month');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('employee_allowances', function (Blueprint $table) {
            $table->dropColumn(['month', 'year']);
        });

        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->dropColumn(['month', 'year']);
        });

        Schema::table('employee_bonuses', function (Blueprint $table) {
            $table->dropColumn(['month', 'year']);
        });
    }
};