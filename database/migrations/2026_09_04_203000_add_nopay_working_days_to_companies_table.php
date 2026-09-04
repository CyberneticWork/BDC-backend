<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'nopay_working_days')) {
                $table->unsignedTinyInteger('nopay_working_days')->default(30)->after('established');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'nopay_working_days')) {
                $table->dropColumn('nopay_working_days');
            }
        });
    }
};
