<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('leave_masters', function (Blueprint $table) {

            // is_short_leave check karala add kireema
            if (!Schema::hasColumn('leave_masters', 'is_short_leave')) {
                $table->boolean('is_short_leave')->default(0)->nullable()->after('is_half_day');
            }

            // short_leave_slot check karala add kireema
            if (!Schema::hasColumn('leave_masters', 'short_leave_slot')) {
                $table->string('short_leave_slot')->nullable()->after('is_short_leave');
            }

            // period check karala add kireema
            if (!Schema::hasColumn('leave_masters', 'period')) {
                $table->string('period')->nullable()->after('short_leave_slot');
            }
        });
    }

    public function down()
    {
        Schema::table('leave_masters', function (Blueprint $table) {
            // Rollback kireedi check karala drop kireema
            $columns = [];
            if (Schema::hasColumn('leave_masters', 'is_short_leave')) $columns[] = 'is_short_leave';
            if (Schema::hasColumn('leave_masters', 'short_leave_slot')) $columns[] = 'short_leave_slot';
            if (Schema::hasColumn('leave_masters', 'period')) $columns[] = 'period';

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};