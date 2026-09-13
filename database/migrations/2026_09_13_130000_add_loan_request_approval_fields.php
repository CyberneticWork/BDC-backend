<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('loans')) {
            return;
        }

        Schema::table('loans', function (Blueprint $table) {
            if (!Schema::hasColumn('loans', 'reason')) {
                $table->text('reason')->nullable();
            }
            if (!Schema::hasColumn('loans', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (!Schema::hasColumn('loans', 'submitted_via')) {
                $table->string('submitted_via', 20)->default('hr');
            }
            if (!Schema::hasColumn('loans', 'processed_by')) {
                $table->unsignedBigInteger('processed_by')->nullable();
            }
            if (!Schema::hasColumn('loans', 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }
        });

        try {
            DB::statement("ALTER TABLE loans MODIFY status VARCHAR(30) NOT NULL DEFAULT 'active'");
        } catch (\Throwable $e) {
            // SQLite / already a string
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('loans')) {
            return;
        }
        Schema::table('loans', function (Blueprint $table) {
            foreach (['reason', 'notes', 'submitted_via', 'processed_by', 'processed_at'] as $col) {
                if (Schema::hasColumn('loans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
