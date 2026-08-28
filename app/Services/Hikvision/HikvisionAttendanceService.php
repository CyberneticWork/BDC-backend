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
        if (!$device->is_active) {
            return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'errors' => ['Device is inactive']];
        }

        if (!$device->polling_enabled && !$this->client->shouldUseCloudBridge($device)) {
            return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'errors' => ['Polling disabled']];
        }

        $from = $from ?? ($device->last_sync_at
            ? $device->last_sync_at->copy()->subMinutes(2)
            : now()->subDays(7));
        $to = $to ?? now();

        $pull = $this->client->fetchAllAccessEvents($device, $from, $to);

        // Cloud bridge mode: no LAN pull — return notice (bridge posts punches separately)
        if (($pull['mode'] ?? null) === 'cloud-bridge') {
            $device->update(['last_sync_at' => now(), 'last_error' => null]);
            return $pull;
        }

        if (!$pull['ok']) {
            $device->update(['last_error' => $pull['message'] ?? 'Sync failed']);
            return [
                'ok' => false,
                'imported' => 0,
                'skipped' => 0,
                'errors' => [$pull['message'] ?? 'Sync failed'],
                'raw_events' => 0,
            ];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $maxSerial = (int) ($device->last_serial_no ?? 0);

        foreach ($pull['events'] as $rawEvent) {
            $parsed = $this->parser->parseAccessEvent($rawEvent);
            if (!$parsed) {
                $skipped++;
                continue;
            }

            $outcome = $this->processPunch($device, $parsed, 'sync');

            if ($outcome['status'] === 'imported') {
                $imported++;
            } elseif ($outcome['status'] === 'duplicate') {
                $skipped++;
            } else {
                $errors[] = $outcome['message'] ?? 'Unknown error';
                $skipped++;
            }

            if (!empty($parsed['serial_no']) && $parsed['serial_no'] > $maxSerial) {
                $maxSerial = $parsed['serial_no'];
            }
        }

        $rawCount = (int) ($pull['raw_events'] ?? count($pull['events']));
        $msg = $rawCount === 0
            ? 'Device connected, but no events in range. Put thumb again, wait 2–3 seconds, then Sync now.'
            : ($imported > 0
                ? "Synced {$imported} new punch(es) ({$skipped} already imported)."
                : "Found {$rawCount} event(s) on device — all already in Time Card. Put a new thumb, then Sync again.");

        $device->update([
            'last_sync_at' => now(),
            'last_serial_no' => $maxSerial,
            'last_event_at' => $imported > 0 ? now() : $device->last_event_at,
            'last_error' => ($imported === 0 && $rawCount === 0)
                ? $msg
                : (empty($errors) ? null : implode('; ', array_slice($errors, 0, 3))),
        ]);

        return [
            'ok' => true,
            'mode' => 'lan',
            'lan_mode' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'raw_events' => $rawCount,
            'errors' => array_slice($errors, 0, 10),
            'message' => $msg,
        ];
    }

    public function handleWebhook(HikvisionDevice $device, Request $request): array
    {
        if (!$device->is_active || !$device->webhook_enabled) {
            return ['imported' => 0, 'skipped' => 0, 'message' => 'Webhook disabled'];
        }

        $events = $this->parser->parseWebhookPayload($request);

        return $this->importParsedEvents($device, $events, 'webhook');
    }

    /** Office bridge agent: { punches: [ { empNo, date, time, serialNo? } ] } */
    public function handleAgentPunches(HikvisionDevice $device, Request $request): array
    {
        if (!$device->is_active) {
            return ['imported' => 0, 'skipped' => 0, 'message' => 'Device inactive'];
        }

        $events = $this->parser->parseWebhookPayload($request);

        return $this->importParsedEvents($device, $events, 'bridge');
    }

    private function importParsedEvents(HikvisionDevice $device, array $events, string $source): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($events as $parsed) {
            if (!$parsed) {
                $skipped++;
                continue;
            }

            $outcome = $this->processPunch($device, $parsed, $source);

            if ($outcome['status'] === 'imported') {
                $imported++;
            } else {
                $skipped++;
                if ($outcome['status'] === 'error' && !empty($outcome['message'])) {
                    $errors[] = $outcome['message'];
                }
            }
        }

        $device->update([
            'last_event_at' => $imported > 0 ? now() : $device->last_event_at,
            'last_error' => empty($errors) ? null : implode('; ', array_slice($errors, 0, 3)),
        ]);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    public function processPunch(HikvisionDevice $device, array $parsed, string $source): array
    {
        $eventAt = $parsed['event_at'] instanceof Carbon
            ? $parsed['event_at']
            : Carbon::parse($parsed['event_at']);

        // Serial numbers recycle — unique by device + serial + event time
        if (!empty($parsed['serial_no'])) {
            $dup = HikvisionEventLog::where('device_id', $device->id)
                ->where('serial_no', $parsed['serial_no'])
                ->where('event_time', $eventAt)
                ->exists();

            if ($dup) {
                return ['status' => 'duplicate', 'message' => 'Event already processed'];
            }
        }

        // Soft duplicate within 5 seconds for same employee on device
        $recent = HikvisionEventLog::where('device_id', $device->id)
            ->where('employee_no', $parsed['employee_no'])
            ->where('status', 'imported')
            ->whereBetween('event_time', [
                $eventAt->copy()->subSeconds(5),
                $eventAt->copy()->addSeconds(5),
            ])
            ->exists();

        if ($recent) {
            return ['status' => 'duplicate', 'message' => 'Duplicate within 5 seconds'];
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
                    'event_time' => $eventAt,
                    'source' => $source,
                    'time_card_id' => $timeCardId,
                    'status' => 'imported',
                    'message' => $payload['message'] ?? null,
                ]);

                if (!empty($parsed['serial_no']) && $parsed['serial_no'] > (int) $device->last_serial_no) {
                    $device->update([
                        'last_serial_no' => $parsed['serial_no'],
                        'last_event_at' => now(),
                    ]);
                } else {
                    $device->update(['last_event_at' => now()]);
                }

                return ['status' => 'imported', 'time_card_id' => $timeCardId];
            }

            if ($statusCode === 409) {
                HikvisionEventLog::create([
                    'device_id' => $device->id,
                    'serial_no' => $parsed['serial_no'],
                    'employee_no' => $parsed['employee_no'],
                    'event_time' => $eventAt,
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
                'event_time' => $eventAt,
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
