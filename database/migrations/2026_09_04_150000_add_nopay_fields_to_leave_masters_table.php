<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_masters', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_masters', 'requested_days')) {
                $table->decimal('requested_days', 8, 4)->nullable()->after('leave_duration');
            }
            if (!Schema::hasColumn('leave_masters', 'leave_balance_days')) {
                $table->decimal('leave_balance_days', 8, 4)->nullable()->after('requested_days');
            }
            if (!Schema::hasColumn('leave_masters', 'nopay_days')) {
                $table->decimal('nopay_days', 8, 4)->default(0)->after('leave_balance_days');
            }
            if (!Schema::hasColumn('leave_masters', 'nopay_applied')) {
                $table->boolean('nopay_applied')->default(false)->after('nopay_days');
            }
            if (!Schema::hasColumn('leave_masters', 'nopay_record_id')) {
                $table->unsignedBigInteger('nopay_record_id')->nullable()->after('nopay_applied');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_masters', function (Blueprint $table) {
            $cols = ['requested_days', 'leave_balance_days', 'nopay_days', 'nopay_applied', 'nopay_record_id'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('leave_masters', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
