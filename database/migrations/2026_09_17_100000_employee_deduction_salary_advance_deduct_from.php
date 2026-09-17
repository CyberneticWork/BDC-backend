<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_deductions') && !Schema::hasColumn('employee_deductions', 'deduct_from')) {
            Schema::table('employee_deductions', function (Blueprint $table) {
                $table->string('deduct_from', 20)->nullable()->after('custom_amount');
            });
        }
        if (Schema::hasTable('deductions') && !Schema::hasColumn('deductions', 'deduct_from')) {
            Schema::table('deductions', function (Blueprint $table) {
                $table->string('deduct_from', 20)->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_deductions') && Schema::hasColumn('employee_deductions', 'deduct_from')) {
            Schema::table('employee_deductions', function (Blueprint $table) {
                $table->dropColumn('deduct_from');
            });
        }
        if (Schema::hasTable('deductions') && Schema::hasColumn('deductions', 'deduct_from')) {
            Schema::table('deductions', function (Blueprint $table) {
                $table->dropColumn('deduct_from');
            });
        }
    }
};
