<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_activity_logs')) {
            return;
        }

        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name', 190)->nullable();
            $table->string('user_role', 60)->nullable();
            $table->string('action', 60)->index();
            $table->string('module', 80)->index();
            $table->string('method', 10)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 80)->nullable();
            $table->string('description', 500)->nullable();
            $table->json('meta')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');
    }
};
