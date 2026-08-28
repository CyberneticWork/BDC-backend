<?php

namespace App\Services\Hikvision;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HikvisionEventParser
{
    public function parseAccessEvent(array $event): ?array
    {
        $employeeNo = trim((string) (
            $event['employeeNoString']
            ?? $event['employeeNo']
            ?? $event['empNo']
            ?? $event['employee_no']
            ?? ''
        ));

        if ($employeeNo === '') {
            return null;
        }

        $minor = isset($event['minor']) ? (int) $event['minor'] : null;
        $validMinors = config('hikvision.attendance_minor_codes', []);

        // When minor is present and filter configured, enforce it.
        // Agent punches / bridge payloads often omit minor — still accept.
        if ($minor !== null && $minor !== 0 && !empty($validMinors) && !in_array($minor, $validMinors, true)) {
            return null;
        }

        $datePart = isset($event['date']) ? substr((string) $event['date'], 0, 10) : null;
        $timePart = null;
        foreach (['time', 'clockTime', 'clock_time'] as $key) {
            if (!empty($event[$key]) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', trim((string) $event[$key]))) {
                $timePart = trim((string) $event[$key]);
                break;
            }
        }

        $eventAt = $this->parseEventAt(
            $event['eventTime']
            ?? $event['event_at']
            ?? $event['dateTime']
            ?? null
        );

        if (!$eventAt && $datePart && $timePart) {
            $eventAt = $this->parseEventAt($datePart . 'T' . $timePart);
        }

        if (!$eventAt) {
            $rawTime = $event['time'] ?? $event['dateTime'] ?? null;
            if ($rawTime) {
                $eventAt = $this->parseEventAt($rawTime);
            }
        }

        if (!$eventAt) {
            return null;
        }

        $clock = $this->normalizeClock($timePart) ?: $eventAt->format('H:i:s');
        $date = ($datePart && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePart))
            ? $datePart
            : $eventAt->format('Y-m-d');

        return [
            'employee_no' => $employeeNo,
            'date' => $date,
            'time' => $clock,
            'event_at' => $eventAt,
            'serial_no' => isset($event['serialNo'])
                ? (int) $event['serialNo']
                : (isset($event['serial_no']) ? (int) $event['serial_no'] : null),
            'minor' => $minor,
            'status' => $event['status'] ?? null,
        ];
    }

    public function parseWebhookPayload(Request $request): array
    {
        $events = [];

        $jsonBody = $request->json()->all();
        if (!empty($jsonBody)) {
            $events = array_merge($events, $this->extractEventsFromArray($jsonBody));
        }

        $raw = $request->getContent();
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $events = array_merge($events, $this->extractEventsFromArray($decoded));
            }

            if (Str::contains($raw, ['EventNotificationAlert', 'employeeNo'])) {
                $events = array_merge($events, $this->extractEventsFromXml($raw));
            }
        }

        foreach ($request->all() as $value) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $events = array_merge($events, $this->extractEventsFromArray($decoded));
                } elseif (Str::contains($value, 'EventNotificationAlert')) {
                    $events = array_merge($events, $this->extractEventsFromXml($value));
                }
            }
        }

        $parsed = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $item = $this->parseAccessEvent($event);
            if ($item) {
                $parsed[] = $item;
            }
        }

        return $parsed;
    }

    public function extractEventsFromArray(array $payload): array
    {
        $events = [];

        if (isset($payload['punches']) && is_array($payload['punches'])) {
            foreach ($payload['punches'] as $punch) {
                if (!is_array($punch)) {
                    continue;
                }
                $events[] = [
                    'employeeNoString' => $punch['empNo'] ?? $punch['employeeNo'] ?? $punch['employee_no'] ?? null,
                    'date' => $punch['date'] ?? null,
                    'time' => $punch['time'] ?? $punch['clockTime'] ?? null,
                    'serialNo' => $punch['serialNo'] ?? $punch['serial_no'] ?? null,
                    'status' => $punch['status'] ?? null,
                    'dateTime' => isset($punch['date'], $punch['time'])
                        ? ($punch['date'] . 'T' . $punch['time'])
                        : ($punch['dateTime'] ?? null),
                ];
            }
        }

        if (isset($payload['records']) && is_array($payload['records'])) {
            foreach ($payload['records'] as $row) {
                if (is_array($row)) {
                    $events[] = $row;
                }
            }
        }

        if (isset($payload['events']) && is_array($payload['events'])) {
            foreach ($payload['events'] as $row) {
                if (is_array($row)) {
                    $events[] = $row;
                }
            }
        }

        if (isset($payload['AccessControllerEvent'])) {
            $ace = $payload['AccessControllerEvent'];
            if (is_array($ace)) {
                $events[] = array_merge($ace, [
                    'time' => $ace['dateTime'] ?? ($payload['dateTime'] ?? null),
                    'serialNo' => $payload['serialNo'] ?? ($ace['serialNo'] ?? null),
                    'minor' => $payload['minor'] ?? ($ace['minor'] ?? null),
                ]);
            }
        }

        if (isset($payload['AcsEvent']['InfoList'])) {
            $list = $payload['AcsEvent']['InfoList'];
            if (isset($list['employeeNoString']) || isset($list['employeeNo'])) {
                $events[] = $list;
            } elseif (is_array($list)) {
                foreach ($list as $item) {
                    if (is_array($item)) {
                        $events[] = $item;
                    }
                }
            }
        }

        if (isset($payload['employeeNo']) || isset($payload['employeeNoString']) || isset($payload['empNo'])) {
            $events[] = $payload;
        }

        return $events;
    }

    private function extractEventsFromXml(string $xml): array
    {
        $events = [];
        $doc = @simplexml_load_string($xml);
        if ($doc) {
            $array = json_decode(json_encode($doc), true);
            $ace = $array['AccessControllerEvent'] ?? null;
            if ($ace) {
                $events[] = array_merge($ace, [
                    'time' => $array['dateTime'] ?? ($ace['dateTime'] ?? null),
                    'serialNo' => $array['serialNo'] ?? ($ace['serialNo'] ?? null),
                    'minor' => $array['minor'] ?? ($ace['minor'] ?? null),
                ]);
            }
            return $events;
        }

        // Fallback regex (Solar-style) when XML is incomplete
        if (preg_match('/<employeeNoString>([^<]+)<\/employeeNoString>/i', $xml, $emp)
            || preg_match('/<employeeNo>([^<]+)<\/employeeNo>/i', $xml, $emp)
        ) {
            $time = null;
            if (preg_match('/<dateTime>([^<]+)<\/dateTime>/i', $xml, $tm)) {
                $time = $tm[1];
            } elseif (preg_match('/<time>([^<]+)<\/time>/i', $xml, $tm)) {
                $time = $tm[1];
            }
            $serial = null;
            if (preg_match('/<serialNo>([^<]+)<\/serialNo>/i', $xml, $sn)) {
                $serial = (int) $sn[1];
            }
            if ($time) {
                $events[] = [
                    'employeeNoString' => $emp[1],
                    'time' => $time,
                    'serialNo' => $serial,
                ];
            }
        }

        return $events;
    }

    private function parseEventAt(mixed $raw): ?Carbon
    {
        if ($raw instanceof Carbon) {
            return $raw;
        }
        if ($raw instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($raw));
        }
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = trim((string) $raw);
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $s)) {
            return null;
        }
        try {
            return Carbon::parse($s);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeClock(?string $time): ?string
    {
        if (!$time) {
            return null;
        }
        $time = trim($time);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return null;
    }
}
