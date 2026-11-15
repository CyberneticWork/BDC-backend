<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('over_times') && Schema::hasColumn('over_times', 'status')) {
            try {
                DB::statement("ALTER TABLE over_times MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'");
            } catch (\Throwable $e) {
                // SQLite / already string
            }
        }

        if (Schema::hasTable('time_cards') && Schema::hasColumn('time_cards', 'approval_status')) {
            try {
                DB::statement("ALTER TABLE time_cards MODIFY approval_status VARCHAR(32) NOT NULL DEFAULT 'Pending'");
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function down(): void
    {
        // keep widened columns
    }
};
