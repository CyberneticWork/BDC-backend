<?php

namespace App\Services\Zkteco;

use App\Models\ZktecoDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LiveU cloud Attendance API (SenseFP M2 and other LiveU-connected ZKTeco devices).
 * @see LiveU Attendance API Reference v1.0 — https://attapi.liveucloud.com/api/v1
 */
class LiveUApiClient
{
    public function baseUrl(): string
    {
        return (string) config('liveu.base_url');
    }

    public function testConnection(ZktecoDevice $device): array
    {
        $key = $device->api_key;
        if (!$key) {
            return ['ok' => false, 'message' => 'Branch API key is missing. Paste the LiveU x-api-key on the device card.'];
        }

        try {
            $res = Http::timeout(20)
                ->withHeaders(['x-api-key' => $key, 'Accept' => 'application/json'])
                ->get($this->baseUrl().'/members');

            if ($res->status() === 401) {
                return [
                    'ok' => false,
                    'message' => 'LiveU rejected the API key (401). Confirm the Branch API key with Live U — device SN alone is not the key.',
                    'http_status' => 401,
                ];
            }

            if (!$res->successful()) {
                return [
                    'ok' => false,
                    'message' => 'LiveU error HTTP '.$res->status().': '.($res->json('message') ?? $res->body()),
                    'http_status' => $res->status(),
                ];
            }

            $members = $res->json('data') ?? [];
            $count = is_array($members) ? count($members) : 0;

            return [
                'ok' => true,
                'message' => "Connected to LiveU. Branch has {$count} member(s). Device SN filter: {$device->serial_number}.",
                'members' => $count,
            ];
        } catch (\Throwable $e) {
            Log::warning('LiveU test failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Could not reach LiveU: '.$e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool,records?:list<array>,message?:string}
     */
    public function fetchAttendances(ZktecoDevice $device, string $from, string $to): array
    {
        $key = $device->api_key;
        if (!$key) {
            return ['ok' => false, 'message' => 'Branch API key is missing', 'records' => []];
        }

        try {
            $query = [
                'from' => $from,
                'to' => $to,
            ];
            $access = config('liveu.import_access');
            if ($access) {
                $query['access'] = $access;
            }

            $res = Http::timeout(60)
                ->withHeaders(['x-api-key' => $key, 'Accept' => 'application/json'])
                ->get($this->baseUrl().'/attendances', $query);

            if (!$res->successful()) {
                return [
                    'ok' => false,
                    'message' => 'LiveU attendances HTTP '.$res->status().': '.($res->json('message') ?? $res->body()),
                    'records' => [],
                ];
            }

            $rows = $res->json('data') ?? [];
            if (!is_array($rows)) {
                $rows = [];
            }

            $sn = strtoupper(trim((string) $device->serial_number));
            if ($sn !== '') {
                $rows = array_values(array_filter($rows, function ($row) use ($sn) {
                    $deviceSn = strtoupper((string) data_get($row, 'device.sn', data_get($row, 'device.serial', '')));

                    return $deviceSn === '' || $deviceSn === $sn;
                }));
            }

            return ['ok' => true, 'records' => $rows, 'raw_count' => count($res->json('data') ?? [])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'records' => []];
        }
    }
}
