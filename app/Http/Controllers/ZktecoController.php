<?php

namespace App\Http\Controllers;

use App\Models\ZktecoDevice;
use App\Models\ZktecoEventLog;
use App\Services\Zkteco\ZktecoAttendanceService;
use Illuminate\Http\Request;

class ZktecoController extends Controller
{
    public function __construct(private ZktecoAttendanceService $attendance)
    {
    }

    public function index()
    {
        ZktecoAttendanceService::ensureTables();

        $devices = ZktecoDevice::with('company:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (ZktecoDevice $d) => $this->formatDevice($d));

        return response()->json(['data' => $devices]);
    }

    public function store(Request $request)
    {
        ZktecoAttendanceService::ensureTables();

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'model' => 'nullable|string|max:120',
            'serial_number' => 'required|string|max:120|unique:zkteco_devices,serial_number',
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'company_id' => 'nullable|exists:companies,id',
            'is_active' => 'boolean',
            'adms_enabled' => 'boolean',
        ]);

        $device = ZktecoDevice::create([
            ...$validated,
            'model' => $validated['model'] ?? 'K40 Pro',
            'port' => $validated['port'] ?? 4370,
            'is_active' => $validated['is_active'] ?? true,
            'adms_enabled' => $validated['adms_enabled'] ?? true,
        ]);

        return response()->json([
            'message' => 'ZKTeco device saved. Set the ADMS URL on the K40 Pro to the Server URL shown below.',
            'data' => $this->formatDevice($device->fresh('company')),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        ZktecoAttendanceService::ensureTables();
        $device = ZktecoDevice::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'model' => 'nullable|string|max:120',
            'serial_number' => 'sometimes|string|max:120|unique:zkteco_devices,serial_number,'.$device->id,
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'company_id' => 'nullable|exists:companies,id',
            'is_active' => 'boolean',
            'adms_enabled' => 'boolean',
        ]);

        $device->fill($validated)->save();

        return response()->json([
            'message' => 'Device updated',
            'data' => $this->formatDevice($device->fresh('company')),
        ]);
    }

    public function destroy(int $id)
    {
        ZktecoAttendanceService::ensureTables();
        ZktecoDevice::findOrFail($id)->delete();

        return response()->json(['message' => 'Device removed']);
    }

    public function eventLogs(int $id, Request $request)
    {
        ZktecoAttendanceService::ensureTables();
        $device = ZktecoDevice::findOrFail($id);
        $limit = min(100, max(5, (int) $request->query('limit', 20)));

        $logs = ZktecoEventLog::where('device_id', $device->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $logs]);
    }

    /** Optional JSON punch push (office helper / test), token-auth like Hikvision. */
    public function punches(Request $request, string $token)
    {
        ZktecoAttendanceService::ensureTables();
        $device = ZktecoDevice::where('webhook_token', $token)->first();
        if (!$device) {
            return response()->json(['message' => 'Unknown device'], 404);
        }
        if (!$device->is_active) {
            return response()->json(['message' => 'Device inactive'], 403);
        }

        $result = $this->attendance->importAgentPunches($device, $request->all());

        return response()->json([
            'message' => "Imported {$result['imported']}, skipped {$result['skipped']}",
            'data' => $result,
        ]);
    }

    public function punchesPing(string $token)
    {
        ZktecoAttendanceService::ensureTables();
        $device = ZktecoDevice::where('webhook_token', $token)->first();
        if (!$device) {
            return response()->json(['message' => 'Unknown device'], 404);
        }

        return response()->json([
            'ok' => true,
            'device' => $device->name,
            'serial_number' => $device->serial_number,
            'adms_url' => $device->admsBaseUrl(),
        ]);
    }

    public function setupGuide()
    {
        $sample = ZktecoDevice::query()->orderByDesc('id')->first();
        $adms = $sample?->admsBaseUrl() ?? (rtrim((string) config('app.url'), '/').'/iclock');

        return response()->json([
            'data' => [
                'steps' => [
                    [
                        'title' => 'Add the K40 Pro in Time Card',
                        'detail' => 'Open ZKTeco panel → Add Device. Enter the device Serial Number (SN) exactly as shown on the terminal (Comm → Device Info). Device User ID must match Attendance Employee No.',
                    ],
                    [
                        'title' => 'Set Cloud / ADMS server on the device',
                        'detail' => "On the K40 Pro: Menu → Comm. → Cloud Server / ADMS. Enable Domain Name or Server Address. Server URL: {$adms}  (no trailing slash). Port: 80 or 443 to match your HR site. Save and reboot if asked.",
                    ],
                    [
                        'title' => 'Confirm handshake',
                        'detail' => 'After the device connects, Last handshake updates on the device card. Then scan a finger — a Time Card row appears automatically (IN/OUT toggle same as Hikvision).',
                    ],
                    [
                        'title' => 'Firewall / APP_URL',
                        'detail' => 'The device must reach the public HR URL (APP_URL). Local 127.0.0.1 will not work from the terminal. Open HTTPS/HTTP inbound on the server.',
                    ],
                ],
                'adms_url_example' => $adms,
            ],
        ]);
    }

    private function formatDevice(ZktecoDevice $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'model' => $d->model,
            'serial_number' => $d->serial_number,
            'ip_address' => $d->ip_address,
            'port' => $d->port,
            'company_id' => $d->company_id,
            'company_name' => $d->company->name ?? null,
            'is_active' => $d->is_active,
            'adms_enabled' => $d->adms_enabled,
            'adms_url' => $d->admsBaseUrl(),
            'punches_url' => $d->punchesUrl(),
            'last_att_stamp' => $d->last_att_stamp,
            'last_sync_at' => $d->last_sync_at,
            'last_event_at' => $d->last_event_at,
            'last_handshake_at' => $d->last_handshake_at,
            'last_error' => $d->last_error,
            'online' => $d->last_handshake_at && $d->last_handshake_at->gt(now()->subMinutes(10)),
        ];
    }
}
