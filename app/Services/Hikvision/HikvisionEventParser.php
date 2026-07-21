<?php

namespace App\Services\Hikvision;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HikvisionEventParser
{
    public function parseAccessEvent(array $event): ?array
    {
        $minor = (int) ($event['minor'] ?? 0);
        $validMinors = config('hikvision.attendance_minor_codes', []);

        if (!empty($validMinors) && !in_array($minor, $validMinors, true)) {
            return null;
        }

        $employeeNo = trim((string) ($event['employeeNoString'] ?? $event['employeeNo'] ?? ''));
        if ($employeeNo === '') {
            return null;
        }

        $timeRaw = $event['time'] ?? $event['dateTime'] ?? null;
        if (!$timeRaw) {
            return null;
        }

        $eventAt = Carbon::parse($timeRaw);

        return [
            'employee_no' => $employeeNo,
            'date' => $eventAt->format('Y-m-d'),
            'time' => $eventAt->format('H:i:s'),
            'event_at' => $eventAt,
            'serial_no' => isset($event['serialNo']) ? (int) $event['serialNo'] : null,
            'minor' => $minor,
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

            if (Str::contains($raw, '<EventNotificationAlert')) {
                $events = array_merge($events, $this->extractEventsFromXml($raw));
            }
        }

        foreach ($request->all() as $value) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $events = array_merge($events, $this->extractEventsFromArray($decoded));
                } elseif (Str::contains($value, '<EventNotificationAlert')) {
                    $events = array_merge($events, $this->extractEventsFromXml($value));
                }
            }
        }

        $parsed = [];
        foreach $events as $event) {
            $item = $this->parseAccessEvent($event);
            if ($item) {
                $parsed[] = $item;
            }
        }

        return $parsed;
    }

    private function extractEventsFromArray(array $payload): array
    {
        $events = [];

        if (isset($payload['AccessControllerEvent'])) {
            $ace = $payload['AccessControllerEvent'];
            if (isset($ace['employeeNoString']) || isset($ace['employeeNo'])) {
                $events[] = array_merge(
                    $ace,
                    ['time' => $ace['dateTime'] ?? ($payload['dateTime'] ?? null)]
                );
            }
        }

        if (($payload['eventType'] ?? '') === 'AccessControllerEvent' && isset($payload['AccessControllerEvent'])) {
            $ace = $payload['AccessControllerEvent'];
            $events[] = array_merge(
                $ace,
                [
                    'time' => $payload['dateTime'] ?? ($ace['dateTime'] ?? null),
                    'serialNo' => $payload['serialNo'] ?? ($ace['serialNo'] ?? null),
                    'minor' => $payload['minor'] ?? ($ace['minor'] ?? null),
                ]
            );
        }

        if (isset($payload['AcsEvent']['InfoList'])) {
            $list = $payload['AcsEvent']['InfoList'];
            if (isset($list['employeeNoString'])) {
                $events[] = $list;
            } else {
                foreach ($list as $item) {
                    if (is_array($item)) {
                        $events[] = $item;
                    }
                }
            }
        }

        return $events;
    }

    private function extractEventsFromXml(string $xml): array
    {
        $events = [];
        $doc = @simplexml_load_string($xml);
        if (!$doc) {
            return $events;
        }

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
}
