<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (!Schema::hasColumn('loans', 'installment_deduct_from')) {
                $table->string('installment_deduct_from', 20)->nullable()->after('deduct_from');
            }
            if (!Schema::hasColumn('loans', 'interest_deduct_from')) {
                $table->string('interest_deduct_from', 20)->nullable()->after('installment_deduct_from');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (Schema::hasColumn('loans', 'interest_deduct_from')) {
                $table->dropColumn('interest_deduct_from');
            }
            if (Schema::hasColumn('loans', 'installment_deduct_from')) {
                $table->dropColumn('installment_deduct_from');
            }
        });
    }
};
