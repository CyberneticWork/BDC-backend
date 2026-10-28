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
            if (!Schema::hasColumn('compensation', 'paye_active')) {
                $table->boolean('paye_active')->default(false)->after('stamp');
            }
            if (!Schema::hasColumn('compensation', 'holiday_processing')) {
                $table->boolean('holiday_processing')->default(false)->after('paye_active');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('compensation', function (Blueprint $table) {
            if (Schema::hasColumn('compensation', 'holiday_processing')) {
                $table->dropColumn('holiday_processing');
            }
            if (Schema::hasColumn('compensation', 'paye_active')) {
                $table->dropColumn('paye_active');
            }
        });
    }
};
