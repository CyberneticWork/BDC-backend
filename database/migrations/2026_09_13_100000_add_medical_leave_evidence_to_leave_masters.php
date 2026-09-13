<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leave_masters')) {
            return;
        }

        Schema::table('leave_masters', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_masters', 'requires_evidence')) {
                $table->boolean('requires_evidence')->default(false)->after('reason');
            }
            if (!Schema::hasColumn('leave_masters', 'evidence_path')) {
                $table->text('evidence_path')->nullable()->after('requires_evidence');
            }
            if (!Schema::hasColumn('leave_masters', 'evidence_name')) {
                $table->string('evidence_name', 255)->nullable()->after('evidence_path');
            }
            if (!Schema::hasColumn('leave_masters', 'medical_casual_days')) {
                $table->decimal('medical_casual_days', 8, 4)->nullable()->after('nopay_days');
            }
            if (!Schema::hasColumn('leave_masters', 'medical_annual_days')) {
                $table->decimal('medical_annual_days', 8, 4)->nullable()->after('medical_casual_days');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('leave_masters')) {
            return;
        }

        Schema::table('leave_masters', function (Blueprint $table) {
            foreach (['medical_annual_days', 'medical_casual_days', 'evidence_name', 'evidence_path', 'requires_evidence'] as $col) {
                if (Schema::hasColumn('leave_masters', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
