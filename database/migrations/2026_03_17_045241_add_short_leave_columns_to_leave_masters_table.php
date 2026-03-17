<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('leave_masters', function (Blueprint $table) {
            // මේ අලුත් Columns 3 එකතු කරනවා
            //$table->boolean('is_short_leave')->default(0)->nullable()->after('is_half_day');
            //$table->string('short_leave_slot')->nullable()->after('is_short_leave');
            //$table->string('period')->nullable()->after('short_leave_slot');
        });
    }

    public function down()
    {
        Schema::table('leave_masters', function (Blueprint $table) {
            // Rollback කරොත් මේ Columns 3 අයින් වෙනවා
            $table->dropColumn(['is_short_leave', 'short_leave_slot', 'period']);
        });
    }
};