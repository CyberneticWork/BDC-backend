<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'morning_ot_start',
                'special_ot_start',
                'late_deduction',
                'nopay_hour_halfday',
                'break_time'
            ]);
        });
    }

    public function down()
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->time('morning_ot_start')->nullable();
            $table->time('special_ot_start')->nullable();
            $table->time('late_deduction')->nullable();
            $table->decimal('nopay_hour_halfday', 5, 2)->default(0);
            $table->integer('break_time')->default(0);
        });
    }
};