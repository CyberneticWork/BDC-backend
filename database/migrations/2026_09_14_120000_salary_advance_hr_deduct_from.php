<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('salary_advance_requests')) {
            return;
        }
        Schema::table('salary_advance_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('salary_advance_requests', 'deduct_from')) {
                $table->string('deduct_from', 20)->nullable()->after('status');
            }
            if (!Schema::hasColumn('salary_advance_requests', 'source')) {
                $table->string('source', 20)->nullable()->after('created_by');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('salary_advance_requests')) {
            return;
        }
        Schema::table('salary_advance_requests', function (Blueprint $table) {
            if (Schema::hasColumn('salary_advance_requests', 'deduct_from')) {
                $table->dropColumn('deduct_from');
            }
            if (Schema::hasColumn('salary_advance_requests', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
