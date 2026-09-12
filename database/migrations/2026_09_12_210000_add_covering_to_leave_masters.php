<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leave_masters')) {
            return;
        }
        Schema::table('leave_masters', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_masters', 'covering_employee_id')) {
                $table->unsignedBigInteger('covering_employee_id')->nullable()->after('employee_id');
            }
            if (!Schema::hasColumn('leave_masters', 'covering_status')) {
                $table->string('covering_status', 30)->nullable()->after('covering_employee_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('leave_masters')) {
            return;
        }
        Schema::table('leave_masters', function (Blueprint $table) {
            if (Schema::hasColumn('leave_masters', 'covering_status')) {
                $table->dropColumn('covering_status');
            }
            if (Schema::hasColumn('leave_masters', 'covering_employee_id')) {
                $table->dropColumn('covering_employee_id');
            }
        });
    }
};
