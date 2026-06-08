<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compensation', function (Blueprint $table) {
            $table->decimal('monthly_bonus', 12, 2)->nullable()->default(0)->after('basic_salary');
            $table->decimal('sports_fund_percentage', 5, 2)->nullable()->after('monthly_bonus');
            $table->decimal('staff_fund_amount', 12, 2)->nullable()->default(0)->after('sports_fund_percentage');
        });

        Schema::table('bonuses', function (Blueprint $table) {
            $table->boolean('is_annual')->default(false)->after('bonus_type');
            $table->json('payment_months')->nullable()->after('is_annual');
        });

        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                if (!Schema::hasColumn('companies', 'default_sports_fund_percentage')) {
                    $table->decimal('default_sports_fund_percentage', 5, 2)->nullable()->after('name');
                }
            });
        }

        if (Schema::hasTable('allowances')) {
            DB::statement("ALTER TABLE allowances MODIFY COLUMN category ENUM('travel', 'bonus', 'monthly_bonus', 'performance', 'health', 'other') NULL DEFAULT 'other'");
        }
    }

    public function down(): void
    {
        Schema::table('compensation', function (Blueprint $table) {
            $table->dropColumn(['monthly_bonus', 'sports_fund_percentage', 'staff_fund_amount']);
        });

        Schema::table('bonuses', function (Blueprint $table) {
            $table->dropColumn(['is_annual', 'payment_months']);
        });

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'default_sports_fund_percentage')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('default_sports_fund_percentage');
            });
        }
    }
};
