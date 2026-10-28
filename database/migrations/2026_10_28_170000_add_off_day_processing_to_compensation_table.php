<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('compensation', function (Blueprint $table) {
            if (!Schema::hasColumn('compensation', 'off_day_processing')) {
                $table->boolean('off_day_processing')->default(false)->after('holiday_processing');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('compensation', function (Blueprint $table) {
            if (Schema::hasColumn('compensation', 'off_day_processing')) {
                $table->dropColumn('off_day_processing');
            }
        });
    }
};
