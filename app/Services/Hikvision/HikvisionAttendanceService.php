<?php

namespace App\Services\Hikvision;

use App\Http\Controllers\TimeCardController;
use App\Models\HikvisionDevice;
use App\Models\HikvisionEventLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HikvisionAttendanceService
{
    public function __construct(
        private HikvisionIsapiClient $client,
        private HikvisionEventParser $parser,
    ) {
    }

    public function syncDevice(HikvisionDevice $device, ?Carbon $from = null, ?Carbon $to = null): array
    {
        if (!$device->is_active || !$device->polling_enabled) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Device is inactive or polling disabled']];
        }

        $from = $from ?? ($device->last_sync_at ? $device->last_sync_at->copy()->subMinutes(2) : now()->subDay());
        $to = $to ?? now();

        $startTime = $from->format('Y-m-d\TH:i:s');
        $endTime = $to->format('Y-m-d\TH:i:s');

        $result = $this->client->fetchAccessEvents($device, $startTime, $endTime, 0, 200);

        if (!$result['ok']) {
            $device->update(['last_error' => $result['message'] ?? 'Sync failed']);
            return ['imported' => 0, 'skipped' => 0, 'errors' => [$result['message'] ?? 'Sync failed']];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $maxSerial = $device->last_serial_no;

        foreach ($result['events'] as $rawEvent) {
            $parsed = $this->parser->parseAccessEvent($rawEvent);
            if (!$parsed) {
                $skipped++;
                continue;
            }

            if ($parsed['serial_no'] && $parsed['serial_no'] <= $device->last_serial_no) {
                $skipped++;
                continue;
            }

            $outcome = $this->processPunch($device, $parsed, 'poll');

            if ($outcome['status'] === 'imported') {
                $imported++;
            } elseif ($outcome['status'] === 'duplicate') {
                $skipped++;
            } else {
                $errors[] = $outcome['message'] ?? 'Unknown error';
                $skipped++;
            }

            if ($parsed['serial_no'] && $parsed['serial_no'] > $maxSerial) {
                $maxSerial = $parsed['serial_no'];
            }
        }

        $device->update([
            'last_sync_at' => now(),
            'last_serial_no' => $maxSerial,
            'last_error' => empty($errors) ? null : implode('; ', array_slice($errors, 0, 3)),
        ]);

        return compact('imported', 'skipped', 'errors');
    }

    public function handleWebhook(HikvisionDevice $device, Request $request): array
    {
        if (!$device->is_active || !$device->webhook_enabled) {
            return ['imported' => 0, 'message' => 'Webhook disabled'];
        }

        $events = $this->parser->parseWebhookPayload($request);

        $imported = 0;
        $skipped = 0;

        foreach ($events as $parsed) {
            if (!$parsed) {
                $skipped++;
                continue;
            }

            $outcome = $this->processPunch($device, $parsed, 'webhook');

            if ($outcome['status'] === 'imported') {
                $imported++;
            } else {
                $skipped++;
            }
        }

        $device->update(['last_event_at' => now()]);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    public function processPunch(HikvisionDevice $device, array $parsed, string $source): array
    {
        if ($parsed['serial_no']) {
            $exists = HikvisionEventLog::where('device_id', $device->id)
                ->where('serial_no', $parsed['serial_no'])
                ->exists();

            if ($exists) {
                return ['status' => 'duplicate', 'message' => 'Event already processed'];
            }
        }

        $request = Request::create('/api/attendance', 'POST', [
            'empno' => $parsed['employee_no'],
            'date' => $parsed['date'],
            'time' => $parsed['time'],
        ]);

        try {
            $response = app(TimeCardController::class)->attendance($request);
            $statusCode = $response->getStatusCode();
            $payload = json_decode($response->getContent(), true);

            if ($statusCode === 201) {
                $timeCardId = $payload['data']['id'] ?? null;

                HikvisionEventLog::create([
                    'device_id' => $device->id,
                    'serial_no' => $parsed['serial_no'],
                    'employee_no' => $parsed['employee_no'],
                    'event_time' => $parsed['event_at'],
                    'source' => $source,
                    'time_card_id' => $timeCardId,
                    'status' => 'imported',
                    'message' => $payload['message'] ?? null,
                ]);

                if ($parsed['serial_no'] && $parsed['serial_no'] > $device->last_serial_no) {
                    $device->update(['last_serial_no' => $parsed['serial_no']]);
                }

                return ['status' => 'imported', 'time_card_id' => $timeCardId];
            }

            if ($statusCode === 409) {
                HikvisionEventLog::create([
                    'device_id' => $device->id,
                    'serial_no' => $parsed['serial_no'],
                    'employee_no' => $parsed['employee_no'],
                    'event_time' => $parsed['event_at'],
                    'source' => $source,
                    'status' => 'skipped',
                    'message' => $payload['message'] ?? 'Duplicate within 5 minutes',
                ]);

                return ['status' => 'duplicate', 'message' => $payload['message'] ?? null];
            }

            $message = $payload['message'] ?? ($payload['errors'] ?? 'Attendance rejected');

            HikvisionEventLog::create([
                'device_id' => $device->id,
                'serial_no' => $parsed['serial_no'],
                'employee_no' => $parsed['employee_no'],
                'event_time' => $parsed['event_at'],
                'source' => $source,
                'status' => 'error',
                'message' => is_array($message) ? json_encode($message) : $message,
            ]);

            Log::info('Hikvision punch rejected', [
                'device_id' => $device->id,
                'employee_no' => $parsed['employee_no'],
                'message' => $message,
            ]);

            return ['status' => 'error', 'message' => is_array($message) ? json_encode($message) : $message];
        } catch (\Throwable $e) {
            Log::error('Hikvision punch failed', [
                'device_id' => $device->id,
                'employee_no' => $parsed['employee_no'],
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
