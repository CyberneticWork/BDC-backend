<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hikvision_devices')) {
            return;
        }

        Schema::create('hikvision_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('ip_address');
            $table->unsignedSmallInteger('port')->default(80);
            $table->string('username')->default('admin');
            $table->text('password_encrypted');
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('webhook_enabled')->default(true);
            $table->boolean('polling_enabled')->default(true);
            $table->string('webhook_token', 64)->unique();
            $table->unsignedBigInteger('last_serial_no')->default(0);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('hikvision_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('hikvision_devices')->cascadeOnDelete();
            $table->unsignedBigInteger('serial_no')->nullable();
            $table->string('employee_no')->nullable();
            $table->timestamp('event_time');
            $table->string('source', 20);
            $table->unsignedBigInteger('time_card_id')->nullable();
            $table->string('status', 30)->default('processed');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'serial_no']);
            $table->index(['device_id', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hikvision_event_logs');
        Schema::dropIfExists('hikvision_devices');
    }
};
