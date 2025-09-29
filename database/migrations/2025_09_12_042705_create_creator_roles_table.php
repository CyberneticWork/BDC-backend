<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('creator_roles', function (Blueprint $table) {
            $table->id();
            $table->string('role_name');
            $table->timestamps();
        });

        Schema::table('creator_roles', function (Blueprint $table) {
            if (!Schema::hasColumn('creator_roles', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }

            // create composite unique index on role_name + deleted_at to allow same name when previous row soft-deleted
            try {
                $table->unique(['role_name', 'deleted_at'], 'creator_roles_role_name_deleted_at_unique');
            } catch (\Throwable $e) {
                // ignore if index exists
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creator_roles', function (Blueprint $table) {
            try {
                $table->dropUnique('creator_roles_role_name_deleted_at_unique');
            } catch (\Throwable $e) { }

            if (Schema::hasColumn('creator_roles', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::dropIfExists('creator_roles');
    }
};
