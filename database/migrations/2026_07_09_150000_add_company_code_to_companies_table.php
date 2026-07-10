<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        if (!Schema::hasColumn('companies', 'company_code')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('company_code', 50)->nullable()->after('id');
            });

            // Backfill existing rows with a placeholder code so the unique index can be applied safely.
            $companies = DB::table('companies')->get(['id']);
            foreach ($companies as $c) {
                DB::table('companies')
                    ->where('id', $c->id)
                    ->update(['company_code' => 'CMP-' . str_pad($c->id, 4, '0', STR_PAD_LEFT)]);
            }
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->string('company_code', 50)->nullable(false)->change();
            $table->unique('company_code', 'companies_company_code_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        if (Schema::hasColumn('companies', 'company_code')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropUnique('companies_company_code_unique');
                $table->dropColumn('company_code');
            });
        }
    }
};
