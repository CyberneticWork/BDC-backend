<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_notices')) {
            return;
        }
        Schema::table('hr_notices', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_notices', 'location_id')) {
                $table->unsignedBigInteger('location_id')->nullable()->after('department_id')->index();
            }
            if (!Schema::hasColumn('hr_notices', 'employee_ids')) {
                $table->json('employee_ids')->nullable()->after('location_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('hr_notices')) {
            return;
        }
        Schema::table('hr_notices', function (Blueprint $table) {
            if (Schema::hasColumn('hr_notices', 'employee_ids')) {
                $table->dropColumn('employee_ids');
            }
            if (Schema::hasColumn('hr_notices', 'location_id')) {
                $table->dropColumn('location_id');
            }
        });
    }
};
