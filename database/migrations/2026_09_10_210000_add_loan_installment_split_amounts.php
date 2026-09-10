<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (!Schema::hasColumn('loans', 'deduct_basic_amount')) {
                $table->decimal('deduct_basic_amount', 12, 2)->nullable()->after('deduct_from');
            }
            if (!Schema::hasColumn('loans', 'deduct_bonus_amount')) {
                $table->decimal('deduct_bonus_amount', 12, 2)->nullable()->after('deduct_basic_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (Schema::hasColumn('loans', 'deduct_bonus_amount')) {
                $table->dropColumn('deduct_bonus_amount');
            }
            if (Schema::hasColumn('loans', 'deduct_basic_amount')) {
                $table->dropColumn('deduct_basic_amount');
            }
        });
    }
};
