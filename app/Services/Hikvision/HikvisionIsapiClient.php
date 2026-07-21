<?php

namespace App\Services\Hikvision;

use App\Models\HikvisionDevice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HikvisionIsapiClient
{
    public function testConnection(HikvisionDevice $device): array
    {
        $response = $this->request($device, 'GET', '/ISAPI/System/deviceInfo');

        if (!$response['ok']) {
            return $response;
        }

        $body = $response['body'];
        $info = [];

        if (is_array($body)) {
            $info = $body['DeviceInfo'] ?? $body;
        } elseif (is_string($body)) {
            $xml = @simplexml_load_string($body);
            if ($xml) {
                $info = json_decode(json_encode($xml), true);
            }
        }

        return [
            'ok' => true,
            'device_info' => $info,
            'message' => 'Connected successfully',
        ];
    }

    public function fetchAccessEvents(HikvisionDevice $device, string $startTime, string $endTime, int $position = 0, int $maxResults = 100): array
    {
        $payload = [
            'AcsEventCond' => [
                'searchID' => 'hr-sync-' . $device->id . '-' . time(),
                'searchResultPosition' => $position,
                'maxResults' => $maxResults,
                'major' => config('hikvision.access_major_code', 5),
                'minor' => 0,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'timeReverseOrder' => false,
            ],
        ];

        $response = $this->request($device, 'POST', '/ISAPI/AccessControl/AcsEvent?format=json', $payload);

        if (!$response['ok']) {
            return $response;
        }

        $body = $response['body'];
        $events = $body['AcsEvent']['InfoList'] ?? [];

        if (isset($events['major']) || isset($events['employeeNoString'])) {
            $events = [$events];
        }

        return [
            'ok' => true,
            'events' => is_array($events) ? $events : [],
            'total_matches' => (int) ($body['AcsEvent']['totalMatches'] ?? count($events)),
        ];
    }

    public function configureWebhook(HikvisionDevice $device): array
    {
        $hostId = config('hikvision.http_host_id', 1);
        $url = $device->webhookUrl();

        $payload = [
            'HttpHostNotificationList' => [
                'HttpHostNotification' => [
                    [
                        'id' => $hostId,
                        'url' => $url,
                        'protocolType' => 'HTTP',
                        'parameterFormatType' => 'JSON',
                        'addressingFormatType' => 'hostname',
                        'portNo' => parse_url(config('app.url'), PHP_URL_PORT) ?: 80,
                        'httpAuthenticationMethod' => 'none',
                        'SubscribeEvent' => [
                            'eventMode' => 'all',
                            'EventList' => [
                                ['type' => 'AccessControllerEvent'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->request(
            $device,
            'PUT',
            '/ISAPI/Event/notification/httpHosts?format=json',
            $payload
        );

        if (!$response['ok']) {
            $xmlPayload = $this->buildHttpHostXml($hostId, $url);
            $response = $this->request(
                $device,
                'PUT',
                '/ISAPI/Event/notification/httpHosts/' . $hostId,
                $xmlPayload,
                'application/xml'
            );
        }

        return $response;
    }

    private function buildHttpHostXml(int $hostId, string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 80;
        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<HttpHostNotification version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">
  <id>{$hostId}</id>
  <url>{$path}</url>
  <protocolType>HTTP</protocolType>
  <parameterFormatType>JSON</parameterFormatType>
  <addressingFormatType>ipaddress</addressingFormatType>
  <ipAddress>{$host}</ipAddress>
  <portNo>{$port}</portNo>
  <httpAuthenticationMethod>none</httpAuthenticationMethod>
</HttpHostNotification>
XML;
    }

    private function request(HikvisionDevice $device, string $method, string $path, mixed $body = null, ?string $contentType = null): array
    {
        $url = rtrim($device->baseUrl(), '/') . $path;
        $password = $device->password;

        if (!$password) {
            return ['ok' => false, 'message' => 'Device password is missing or cannot be decrypted'];
        }

        try {
            $pending = Http::timeout(15)
                ->withOptions(['verify' => false])
                ->withDigestAuth($device->username, $password);

            if ($body !== null) {
                if ($contentType === 'application/xml') {
                    $response = $pending
                        ->withHeaders(['Content-Type' => 'application/xml'])
                        ->send($method, $url, ['body' => $body]);
                } elseif (is_array($body)) {
                    $response = $pending
                        ->acceptJson()
                        ->asJson()
                        ->send($method, $url, ['json' => $body]);
                } else {
                    $response = $pending->send($method, $url, ['body' => $body]);
                }
            } else {
                $response = $pending->send($method, $url);
            }

            if (!$response->successful()) {
                Log::warning('Hikvision ISAPI request failed', [
                    'device_id' => $device->id,
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'message' => 'Device returned HTTP ' . $response->status(),
                    'body' => $response->body(),
                ];
            }

            $responseBody = $response->body();
            $json = json_decode($responseBody, true);

            return [
                'ok' => true,
                'status' => $response->status(),
                'body' => $json ?? $responseBody,
            ];
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'message' => 'Cannot reach device at ' . $device->ip_address . ': ' . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
