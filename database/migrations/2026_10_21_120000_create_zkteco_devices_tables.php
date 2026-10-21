<?php

use App\Services\Zkteco\ZktecoAttendanceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ZktecoAttendanceService::ensureTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_event_logs');
        Schema::dropIfExists('zkteco_devices');
    }
};
