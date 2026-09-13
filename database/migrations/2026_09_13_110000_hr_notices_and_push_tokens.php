<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_notices')) {
            Schema::create('hr_notices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('scope', 20)->default('all');
                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->string('title');
                $table->text('body');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('user_push_tokens')) {
            Schema::create('user_push_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->text('token');
                $table->string('platform', 30)->default('web');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_push_tokens');
        Schema::dropIfExists('hr_notices');
    }
};
