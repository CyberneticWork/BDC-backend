<?php

namespace App\Console\Commands;

use App\Models\ZktecoDevice;
use App\Services\Zkteco\LiveUAttendanceService;
use App\Services\Zkteco\ZktecoAttendanceService;
use Illuminate\Console\Command;

class SyncLiveUAttendance extends Command
{
    protected $signature = 'liveu:sync-attendance {--device=}';

    protected $description = 'Pull LiveU (SenseFP M2) attendance into Time Card';

    public function handle(LiveUAttendanceService $service): int
    {
        ZktecoAttendanceService::ensureTables();

        $query = ZktecoDevice::query()
            ->where('is_active', true)
            ->where('connection_mode', 'liveu');

        if ($this->option('device')) {
            $query->where('id', (int) $this->option('device'));
        }

        $devices = $query->get();
        if ($devices->isEmpty()) {
            $this->info('No active LiveU devices.');

            return self::SUCCESS;
        }

        foreach ($devices as $device) {
            $result = $service->syncDevice($device);
            $this->line(sprintf(
                '#%d %s — imported %d, skipped %d — %s',
                $device->id,
                $device->name,
                $result['imported'] ?? 0,
                $result['skipped'] ?? 0,
                $result['message'] ?? ''
            ));
        }

        return self::SUCCESS;
    }
}
