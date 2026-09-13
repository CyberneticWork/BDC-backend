<?php

namespace App\Console\Commands;

use App\Services\AttendancePunchAlertService;
use Illuminate\Console\Command;

class SendMissedPunchAlerts extends Command
{
    protected $signature = 'attendance:missed-punch-alerts';

    protected $description = 'Notify employees and HR when IN/OUT fingerprint is missing after shift start or end (skip approved leave)';

    public function handle(AttendancePunchAlertService $service): int
    {
        $result = $service->run();
        $this->info("Missed punch alerts sent={$result['sent']} skipped_leave={$result['skipped_leave']}");

        return self::SUCCESS;
    }
}
