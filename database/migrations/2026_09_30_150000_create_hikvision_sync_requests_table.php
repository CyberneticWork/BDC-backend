<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hikvision_sync_requests')) {
            Schema::create('hikvision_sync_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('device_id')->constrained('hikvision_devices')->cascadeOnDelete();
                $table->date('from_date');
                $table->date('to_date');
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedInteger('device_punches')->default(0);
                $table->unsignedInteger('imported')->default(0);
                $table->unsignedInteger('skipped')->default(0);
                $table->text('message')->nullable();
                $table->text('errors')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['device_id', 'status']);
            });
        }

        if (Schema::hasTable('hikvision_devices') && !Schema::hasColumn('hikvision_devices', 'agent_last_seen_at')) {
            Schema::table('hikvision_devices', function (Blueprint $table) {
                $table->timestamp('agent_last_seen_at')->nullable()->after('last_event_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hikvision_sync_requests');

        if (Schema::hasColumn('hikvision_devices', 'agent_last_seen_at')) {
            Schema::table('hikvision_devices', function (Blueprint $table) {
                $table->dropColumn('agent_last_seen_at');
            });
        }
    }
};
