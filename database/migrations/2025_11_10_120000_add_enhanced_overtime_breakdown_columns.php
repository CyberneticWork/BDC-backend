<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('over_times', function (Blueprint $table) {
            $table->decimal('morning_ot_special', 8, 2)->default(0)->after('afternoon_ot');
            $table->decimal('evening_ot_special', 8, 2)->default(0)->after('morning_ot_special');
            $table->decimal('morning_ot_amount', 12, 2)->default(0)->after('evening_ot_special');
            $table->decimal('morning_ot_special_amount', 12, 2)->default(0)->after('morning_ot_amount');
            $table->decimal('evening_ot_amount', 12, 2)->default(0)->after('morning_ot_special_amount');
            $table->decimal('evening_ot_special_amount', 12, 2)->default(0)->after('evening_ot_amount');
            $table->decimal('total_ot_amount', 12, 2)->default(0)->after('evening_ot_special_amount');
        });
    }

    public function down(): void
    {
        Schema::table('over_times', function (Blueprint $table) {
            $table->dropColumn([
                'morning_ot_special',
                'evening_ot_special',
                'morning_ot_amount',
                'morning_ot_special_amount',
                'evening_ot_amount',
                'evening_ot_special_amount',
                'total_ot_amount',
            ]);
        });
    }
};
