<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('time_cards') && !Schema::hasColumn('time_cards', 'shift_id')) {
            Schema::table('time_cards', function (Blueprint $table) {
                $table->unsignedBigInteger('shift_id')->nullable()->after('employee_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('time_cards') && Schema::hasColumn('time_cards', 'shift_id')) {
            Schema::table('time_cards', function (Blueprint $table) {
                $table->dropColumn('shift_id');
            });
        }
    }
};
