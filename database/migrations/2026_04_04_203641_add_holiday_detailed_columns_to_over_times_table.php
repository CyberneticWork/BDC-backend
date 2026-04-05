<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
{
    Schema::table('over_times', function (Blueprint $table) {
        // මේ තීරු ටික Database එකේ නැති නිසා තමයි Error එක එන්නේ
        $table->decimal('holiday_shift_hours', 8, 2)->default(0)->after('holiday_ot_hours');
        $table->decimal('holiday_outside_hours', 8, 2)->default(0)->after('holiday_shift_hours');
        $table->decimal('holiday_shift_amount', 12, 2)->default(0)->after('holiday_ot_amount');
        $table->decimal('holiday_outside_amount', 12, 2)->default(0)->after('holiday_shift_amount');
    });
}

public function down()
{
    Schema::table('over_times', function (Blueprint $table) {
        $table->dropColumn(['holiday_shift_hours', 'holiday_outside_hours', 'holiday_shift_amount', 'holiday_outside_amount']);
    });
}
};
