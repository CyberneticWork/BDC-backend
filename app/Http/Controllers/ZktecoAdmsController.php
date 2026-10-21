<?php

namespace App\Http\Controllers;

use App\Services\Zkteco\ZktecoAttendanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * ZKTeco ADMS / push protocol endpoints for K40 Pro.
 * Devices call /iclock/cdata and /iclock/getrequest (plain text, not JSON).
 */
class ZktecoAdmsController extends Controller
{
    public function __construct(private ZktecoAttendanceService $attendance)
    {
    }

    public function cdata(Request $request): Response
    {
        ZktecoAttendanceService::ensureTables();

        $sn = (string) ($request->query('SN') ?: $request->query('sn') ?: '');
        $device = $this->attendance->findBySerial($sn);

        if (!$device) {
            Log::warning('ZKTeco ADMS unknown SN', ['sn' => $sn, 'ip' => $request->ip()]);

            return $this->plain('Unknown Device', 404);
        }

        if (!$device->is_active || !$device->adms_enabled) {
            return $this->plain('Device Disabled', 403);
        }

        $table = strtoupper((string) ($request->query('table') ?: $request->query('Table') ?: ''));
        $options = (string) ($request->query('options') ?: '');

        // Handshake / get options
        if ($options !== '' || ($request->isMethod('GET') && $table === '')) {
            $device->update(['last_handshake_at' => now(), 'last_error' => null]);

            return $this->plain($this->attendance->optionsResponse($device));
        }

        // Attendance push
        if ($table === 'ATTLOG' || $table === 'ATT_LOG') {
            $body = (string) $request->getContent();
            $stamp = (string) ($request->query('Stamp') ?: $request->query('stamp') ?: '');
            $result = $this->attendance->importAttLog($device, $body, $stamp !== '' ? $stamp : null);
            // Device expects "OK: N"
            $n = (int) ($result['ok_count'] ?? (($result['imported'] ?? 0) + ($result['skipped'] ?? 0)));

            return $this->plain('OK: '.$n);
        }

        // Operlog / user updates — acknowledge so the device clears its queue
        if (in_array($table, ['OPERLOG', 'OPTIONS', 'USERINFO', 'BIODATA', 'ERRORLOG'], true)) {
            if ($table === 'OPERLOG') {
                $opStamp = (string) ($request->query('Stamp') ?: $request->query('OpStamp') ?: '');
                if ($opStamp !== '') {
                    $device->update(['last_op_stamp' => substr($opStamp, 0, 40)]);
                }
            }

            return $this->plain('OK');
        }

        // Default GET without table still returns options
        if ($request->isMethod('GET')) {
            $device->update(['last_handshake_at' => now()]);

            return $this->plain($this->attendance->optionsResponse($device));
        }

        return $this->plain('OK');
    }

    public function getRequest(Request $request): Response
    {
        ZktecoAttendanceService::ensureTables();

        $sn = (string) ($request->query('SN') ?: $request->query('sn') ?: '');
        $device = $this->attendance->findBySerial($sn);
        if (!$device || !$device->is_active) {
            return $this->plain('OK');
        }

        $device->update(['last_handshake_at' => now()]);

        // No remote commands yet — keep the device happy
        return $this->plain('OK');
    }

    public function deviceCmd(Request $request): Response
    {
        return $this->plain('OK');
    }

    /** Some firmwares hit /iclock/push or /iclock/registry */
    public function push(Request $request): Response
    {
        return $this->cdata($request);
    }

    private function plain(string $body, int $status = 200): Response
    {
        return response($body, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
