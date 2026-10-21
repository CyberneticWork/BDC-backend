<?php

namespace App\Services\Zkteco;

use Carbon\Carbon;

/**
 * Parse ZKTeco ADMS / BioTime push payloads (K40 Pro ATTLOG lines).
 *
 * Typical ATTLOG line (tab or multi-space separated):
 *   PIN  DateTime  Status  Verify  WorkCode  ...
 * DateTime: YYYY-MM-DD HH:MM:SS
 */
class ZktecoAdmsParser
{
    /**
     * @return list<array{employee_no:string,date:string,time:string,event_at:Carbon,serial_no:int|null,raw_status:?string}>
     */
    public function parseAttLogBody(string $body): array
    {
        $events = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with(strtoupper($line), 'OK')) {
                continue;
            }

            $parsed = $this->parseAttLogLine($line);
            if ($parsed) {
                $events[] = $parsed;
            }
        }

        return $events;
    }

    public function parseAttLogLine(string $line): ?array
    {
        // Prefer tab, then multi-space / comma
        if (str_contains($line, "\t")) {
            $parts = explode("\t", $line);
        } elseif (str_contains($line, ',')) {
            $parts = array_map('trim', explode(',', $line));
        } else {
            $parts = preg_split('/\s+/', $line) ?: [];
        }

        $parts = array_values(array_filter($parts, fn ($p) => $p !== '' && $p !== null));
        if (count($parts) < 2) {
            return null;
        }

        $pin = trim((string) $parts[0]);
        if ($pin === '') {
            return null;
        }

        // Date + time may be one field "YYYY-MM-DD HH:MM:SS" or two fields
        $dateTimeRaw = trim((string) $parts[1]);
        if (isset($parts[2]) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', trim((string) $parts[2]))) {
            $dateTimeRaw .= ' '.trim((string) $parts[2]);
            $statusIdx = 3;
        } else {
            $statusIdx = 2;
        }

        try {
            $eventAt = Carbon::parse($dateTimeRaw);
        } catch (\Throwable) {
            return null;
        }

        $rawStatus = isset($parts[$statusIdx]) ? trim((string) $parts[$statusIdx]) : null;

        return [
            'employee_no' => $pin,
            'date' => $eventAt->toDateString(),
            'time' => $eventAt->format('H:i:s'),
            'event_at' => $eventAt,
            'serial_no' => $this->serialFrom($pin, $eventAt),
            'raw_status' => $rawStatus,
        ];
    }

    /**
     * Manual / bridge JSON punches: { punches: [ { empNo, date, time } ] }
     *
     * @return list<array>
     */
    public function parseJsonPunches(array $payload): array
    {
        $rows = $payload['punches'] ?? $payload['data'] ?? null;
        if (!is_array($rows)) {
            if (!empty($payload['empNo']) || !empty($payload['employee_no'])) {
                $rows = [$payload];
            } else {
                return [];
            }
        }

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pin = (string) ($row['empNo'] ?? $row['employee_no'] ?? $row['pin'] ?? $row['PIN'] ?? '');
            $date = (string) ($row['date'] ?? '');
            $time = (string) ($row['time'] ?? '');
            if ($pin === '' || $date === '' || $time === '') {
                if (!empty($row['datetime']) || !empty($row['event_time'])) {
                    try {
                        $eventAt = Carbon::parse((string) ($row['datetime'] ?? $row['event_time']));
                        $pin = $pin !== '' ? $pin : (string) ($row['empNo'] ?? $row['employee_no'] ?? '');
                        if ($pin === '') {
                            continue;
                        }
                        $events[] = [
                            'employee_no' => $pin,
                            'date' => $eventAt->toDateString(),
                            'time' => $eventAt->format('H:i:s'),
                            'event_at' => $eventAt,
                            'serial_no' => isset($row['serialNo']) ? (int) $row['serialNo'] : $this->serialFrom($pin, $eventAt),
                            'raw_status' => $row['status'] ?? null,
                        ];
                    } catch (\Throwable) {
                    }
                }
                continue;
            }

            try {
                $eventAt = Carbon::parse($date.' '.$time);
            } catch (\Throwable) {
                continue;
            }

            $events[] = [
                'employee_no' => $pin,
                'date' => $eventAt->toDateString(),
                'time' => $eventAt->format('H:i:s'),
                'event_at' => $eventAt,
                'serial_no' => isset($row['serialNo']) ? (int) $row['serialNo'] : $this->serialFrom($pin, $eventAt),
                'raw_status' => $row['status'] ?? null,
            ];
        }

        return $events;
    }

    private function serialFrom(string $pin, Carbon $eventAt): int
    {
        // Unsigned 32-bit style id for dedup (stable for same pin+second)
        return (int) sprintf('%u', crc32($pin.'|'.$eventAt->format('Y-m-d H:i:s')));
    }
}
