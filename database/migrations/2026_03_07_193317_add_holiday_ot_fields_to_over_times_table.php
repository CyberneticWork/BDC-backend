<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHolidayOtFieldsToOverTimesTable extends Migration
{
    public function up(): void
    {
        Schema::table('over_times', function (Blueprint $table) {
            $table->decimal('holiday_ot_hours', 8, 2)->default(0)->after('total_ot_amount');
            $table->decimal('holiday_ot_amount', 12, 2)->default(0)->after('holiday_ot_hours');
        });
    }

    public function down(): void
    {
        Schema::table('over_times', function (Blueprint $table) {
            $table->dropColumn(['holiday_ot_hours', 'holiday_ot_amount']);
        });
    }
}