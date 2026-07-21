<?php

namespace App\Console\Commands;

use App\Models\HikvisionDevice;
use App\Services\Hikvision\HikvisionAttendanceService;
use Illuminate\Console\Command;

class SyncHikvisionAttendance extends Command
{
    protected $signature = 'hikvision:sync-attendance {--device= : Sync a single device ID}';

    protected $description = 'Poll Hikvision fingerprint terminals and import new attendance punches';

    public function handle(HikvisionAttendanceService $service): int
    {
        $query = HikvisionDevice::query()
            ->where('is_active', true)
            ->where('polling_enabled', true);

        if ($deviceId = $this->option('device')) {
            $query->where('id', $deviceId);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->info('No active Hikvision devices configured.');
            return self::SUCCESS;
        }

        foreach ($devices as $device) {
            $this->line("Syncing {$device->name} ({$device->ip_address})...");
            $result = $service->syncDevice($device);
            $this->info("  imported={$result['imported']} skipped={$result['skipped']}");

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $this->warn("  {$error}");
                }
            }
        }

        return self::SUCCESS;
    }
}
