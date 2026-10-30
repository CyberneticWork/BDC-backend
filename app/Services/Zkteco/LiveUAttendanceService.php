<?php

namespace App\Services\Zkteco;

use App\Models\ZktecoDevice;
use App\Models\ZktecoEventLog;
use Carbon\Carbon;

/**
 * Pull LiveU cloud punches for a SenseFP M2 (or other LiveU ZKTeco) into time_cards.
 */
class LiveUAttendanceService
{
    public function __construct(
        private LiveUApiClient $client,
        private ZktecoAttendanceService $attendance,
    ) {
    }

    public function syncDevice(ZktecoDevice $device, ?Carbon $from = null, ?Carbon $to = null): array
    {
        ZktecoAttendanceService::ensureTables();

        if (!$device->is_active) {
            return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'errors' => ['Device is inactive']];
        }

        if (($device->connection_mode ?? 'adms') !== 'liveu') {
            return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'errors' => ['Device is not in LiveU mode']];
        }

        $from = $from ?? ($device->last_sync_at
            ? $device->last_sync_at->copy()->subMinutes(30)
            : now()->subDays(7));
        $to = $to ?? now();

        $pull = $this->client->fetchAttendances(
            $device,
            $from->toDateString(),
            $to->toDateString()
        );

        if (!($pull['ok'] ?? false)) {
            $device->update(['last_error' => $pull['message'] ?? 'LiveU sync failed']);

            return [
                'ok' => false,
                'imported' => 0,
                'skipped' => 0,
                'errors' => [$pull['message'] ?? 'LiveU sync failed'],
            ];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $tz = config('app.timezone') ?: 'Asia/Colombo';

        foreach ($pull['records'] as $row) {
            $parsed = $this->mapRecord($row, $tz);
            if (!$parsed) {
                $skipped++;
                continue;
            }

            // Skip exact LiveU id already imported
            if (!empty($parsed['external_id'])) {
                $exists = ZktecoEventLog::where('device_id', $device->id)
                    ->where('external_id', $parsed['external_id'])
                    ->where('status', '!=', 'error')
                    ->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }
            }

            $outcome = $this->attendance->processPunch($device, $parsed, 'liveu');
            if ($outcome['status'] === 'imported') {
                $imported++;
            } elseif ($outcome['status'] === 'duplicate') {
                $skipped++;
            } else {
                $skipped++;
                if (!empty($outcome['message'])) {
                    $errors[] = $outcome['message'].' ('.$parsed['employee_no'].')';
                }
            }
        }

        $raw = (int) ($pull['raw_count'] ?? count($pull['records']));
        $msg = $imported > 0
            ? "Imported {$imported} punch(es) from LiveU ({$skipped} skipped)."
            : ($raw === 0
                ? 'LiveU connected but no attendance in this date range for this device SN.'
                : "Found {$raw} LiveU record(s) — none new for Time Card ({$skipped} skipped).");

        $device->update([
            'last_sync_at' => now(),
            'last_handshake_at' => now(),
            'last_event_at' => $imported > 0 ? now() : $device->last_event_at,
            'last_error' => empty($errors) ? null : implode('; ', array_slice($errors, 0, 3)),
        ]);

        return [
            'ok' => true,
            'mode' => 'liveu',
            'imported' => $imported,
            'skipped' => $skipped,
            'raw_events' => $raw,
            'errors' => array_slice(array_values(array_unique($errors)), 0, 10),
            'message' => $msg,
        ];
    }

    private function mapRecord(array $row, string $tz): ?array
    {
        $access = (string) ($row['access'] ?? '');
        $want = (string) config('liveu.import_access', 'Granted');
        if ($want !== '' && strcasecmp($access, $want) !== 0 && $access !== '') {
            return null;
        }

        $pin = trim((string) ($row['memberId'] ?? data_get($row, 'member.memberId') ?? ''));
        if ($pin === '') {
            return null;
        }

        $rawDt = $row['dateTime'] ?? $row['datetime'] ?? null;
        if (!$rawDt) {
            return null;
        }

        try {
            $eventAt = Carbon::parse($rawDt)->timezone($tz);
        } catch (\Throwable) {
            return null;
        }

        $externalId = (string) ($row['_id'] ?? $row['id'] ?? '');

        return [
            'employee_no' => $pin,
            'date' => $eventAt->toDateString(),
            'time' => $eventAt->format('H:i:s'),
            'event_at' => $eventAt,
            'serial_no' => $externalId !== '' ? (int) sprintf('%u', crc32($externalId)) : null,
            'external_id' => $externalId !== '' ? $externalId : null,
            'raw_status' => $row['status'] ?? null,
        ];
    }
}
