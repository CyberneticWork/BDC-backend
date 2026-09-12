<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'acl_customized')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('acl_customized')->default(false)->after('role');
            });
        }

        if (!Schema::hasTable('user_acl_permissions')) {
            Schema::create('user_acl_permissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('module_key', 80);
                $table->boolean('can_view')->default(false);
                $table->boolean('can_add')->default(false);
                $table->boolean('can_edit')->default(false);
                $table->boolean('can_delete')->default(false);
                $table->boolean('can_approve')->default(false);
                $table->timestamps();

                $table->unique(['user_id', 'module_key'], 'user_acl_user_module_unique');
                $table->index('module_key');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_acl_permissions');
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'acl_customized')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('acl_customized');
            });
        }
    }
};
