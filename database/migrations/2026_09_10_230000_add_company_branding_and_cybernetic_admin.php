<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'slug')) {
                $table->string('slug', 80)->nullable()->unique()->after('company_code');
            }
            if (!Schema::hasColumn('companies', 'frontend_host')) {
                $table->string('frontend_host', 191)->nullable()->after('slug');
            }
            if (!Schema::hasColumn('companies', 'logo_url')) {
                $table->string('logo_url', 500)->nullable()->after('frontend_host');
            }
            if (!Schema::hasColumn('companies', 'theme_primary')) {
                $table->string('theme_primary', 20)->nullable()->after('logo_url');
            }
            if (!Schema::hasColumn('companies', 'theme_secondary')) {
                $table->string('theme_secondary', 20)->nullable()->after('theme_primary');
            }
            if (!Schema::hasColumn('companies', 'theme_accent')) {
                $table->string('theme_accent', 20)->nullable()->after('theme_secondary');
            }
            if (!Schema::hasColumn('companies', 'theme_json')) {
                $table->json('theme_json')->nullable()->after('theme_accent');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_cybernetic_admin')) {
                $table->boolean('is_cybernetic_admin')->default(false)->after('role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['theme_json', 'theme_accent', 'theme_secondary', 'theme_primary', 'logo_url', 'frontend_host', 'slug'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_cybernetic_admin')) {
                $table->dropColumn('is_cybernetic_admin');
            }
        });
    }
};
