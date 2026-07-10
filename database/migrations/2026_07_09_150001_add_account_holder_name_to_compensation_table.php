<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('compensation')) {
            return;
        }

        if (!Schema::hasColumn('compensation', 'account_holder_name')) {
            Schema::table('compensation', function (Blueprint $table) {
                $table->string('account_holder_name', 150)->nullable()->after('bank_account_no');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('compensation') && Schema::hasColumn('compensation', 'account_holder_name')) {
            Schema::table('compensation', function (Blueprint $table) {
                $table->dropColumn('account_holder_name');
            });
        }
    }
};
