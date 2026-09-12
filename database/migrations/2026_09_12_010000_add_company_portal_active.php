<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'portal_active')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('portal_active')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'portal_active')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('portal_active');
            });
        }
    }
};
