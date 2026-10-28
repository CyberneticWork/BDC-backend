<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('compensation', 'paye_active')) {
            // Update column default to false (0)
            Schema::table('compensation', function (Blueprint $table) {
                $table->boolean('paye_active')->default(false)->change();
            });

            // Set existing compensation rows to false (0) so PAYE is disabled unless manually enabled
            DB::table('compensation')->update(['paye_active' => 0]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('compensation', 'paye_active')) {
            Schema::table('compensation', function (Blueprint $table) {
                $table->boolean('paye_active')->default(false)->change();
            });
        }
    }
};
