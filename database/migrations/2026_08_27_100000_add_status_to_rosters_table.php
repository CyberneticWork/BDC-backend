<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rosters')) {
            return;
        }

        Schema::table('rosters', function (Blueprint $table) {
            if (!Schema::hasColumn('rosters', 'status')) {
                $table->string('status', 20)->default('Active')->after('date_to');
            }
            if (!Schema::hasColumn('rosters', 'cancel_reason')) {
                $table->string('cancel_reason', 255)->nullable()->after('status');
            }
            if (!Schema::hasColumn('rosters', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancel_reason');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rosters')) {
            return;
        }

        Schema::table('rosters', function (Blueprint $table) {
            if (Schema::hasColumn('rosters', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
            if (Schema::hasColumn('rosters', 'cancel_reason')) {
                $table->dropColumn('cancel_reason');
            }
            if (Schema::hasColumn('rosters', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
