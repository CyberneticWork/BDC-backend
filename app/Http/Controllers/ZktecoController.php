<?php

namespace App\Http\Controllers;

use App\Models\company;
use App\Models\CompanyLocation;
use App\Models\ZktecoDevice;
use App\Models\ZktecoEventLog;
use App\Services\BiometricDeviceVisibility;
use App\Services\CompanyLocationService;
use App\Services\Zkteco\LiveUApiClient;
use App\Services\Zkteco\LiveUAttendanceService;
use App\Services\Zkteco\ZktecoAttendanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ZktecoController extends Controller
{
    public function __construct(
        private ZktecoAttendanceService $attendance,
        private LiveUAttendanceService $liveUAttendance,
        private LiveUApiClient $liveUClient,
        private BiometricDeviceVisibility $deviceVisibility,
    ) {
    }

    public function index(Request $request)
    {
        ZktecoAttendanceService::ensureTables();
        app(CompanyLocationService::class)->ensureSchema();

        $devices = ZktecoDevice::with(['company:id,name', 'location:id,name,company_id'])
            ->orderBy('name')
            ->get();

        if ($request->filled('company_id')) {
            $company = company::find((int) $request->query('company_id'));
            if ($company) {
                $devices = $this->deviceVisibility->filterZktecoDevices($devices, [$company]);
            } else {
                $devices = $devices->where('company_id', (int) $request->query('company_id'))->values();
            }
        }
        if ($request->filled('location_id')) {
            $locId = (int) $request->query('location_id');
            $devices = $devices->where('location_id', $locId)->values();
        }

        return response()->json([
            'data' => $devices->map(fn (ZktecoDevice $d) => $this->formatDevice($d))->values(),
        ]);
    }

    public function store(Request $request)
    {
        ZktecoAttendanceService::ensureTables();
        app(CompanyLocationService::class)->ensureSchema();

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'model' => 'nullable|string|max:120',
            'serial_number' => 'required|string|max:120|unique:zkteco_devices,serial_number',
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'company_id' => 'nullable|exists:companies,id',
            'location_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'adms_enabled' => 'boolean',
            'connection_mode' => 'nullable|in:adms,liveu',
            'api_key' => 'nullable|string|max:255',
        ]);

        $mode = $validated['connection_mode'] ?? 'adms';
        if ($mode === 'liveu' && empty($validated['api_key'])) {
            return response()->json(['message' => 'LiveU Branch API key (x-api-key) is required for SenseFP / LiveU mode.'], 422);
        }
        if ($mode === 'liveu' && empty($validated['company_id'])) {
            return response()->json(['message' => 'Select a company for LiveU / SenseFP branch setup.'], 422);
        }

        $locationId = $this->validatedLocationId(
            $validated['location_id'] ?? null,
            $validated['company_id'] ?? null
        );

        $device = new ZktecoDevice([
            'name' => $validated['name'],
            'model' => $validated['model'] ?? ($mode === 'liveu' ? 'SenseFP M2' : 'K40 Pro'),
            'serial_number' => $validated['serial_number'],
            'ip_address' => $validated['ip_address'] ?? null,
            'port' => $validated['port'] ?? ($mode === 'liveu' ? 443 : 4370),
            'company_id' => $validated['company_id'] ?? null,
            'location_id' => $locationId,
            'is_active' => $validated['is_active'] ?? true,
            'adms_enabled' => $mode === 'adms' ? ($validated['adms_enabled'] ?? true) : false,
            'connection_mode' => $mode,
        ]);
        if (!empty($validated['api_key'])) {
            $device->api_key = $validated['api_key'];
        }
        $device->save();

        $message = $mode === 'liveu'
            ? 'LiveU SenseFP branch device saved. Use Test then Sync Now to pull punches into Time Card.'
            : 'ZKTeco device saved. Set the ADMS URL on the terminal to the Server URL shown below.';

        return response()->json([
            'message' => $message,
            'data' => $this->formatDevice($device->fresh(['company', 'location'])),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        ZktecoAttendanceService::ensureTables();
        app(CompanyLocationService::class)->ensureSchema();
        $device = ZktecoDevice::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'model' => 'nullable|string|max:120',
            'serial_number' => 'sometimes|string|max:120|unique:zkteco_devices,serial_number,'.$device->id,
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'company_id' => 'nullable|exists:companies,id',
            'location_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'adms_enabled' => 'boolean',
            'connection_mode' => 'nullable|in:adms,liveu',
            'api_key' => 'nullable|string|max:255',
        ]);

        if (array_key_exists('location_id', $validated) || array_key_exists('company_id', $validated)) {
            $companyId = array_key_exists('company_id', $validated)
                ? $validated['company_id']
                : $device->company_id;
            $validated['location_id'] = $this->validatedLocationId(
                array_key_exists('location_id', $validated) ? $validated['location_id'] : $device->location_id,
                $companyId
            );
        }

        $device->fill(collect($validated)->except('api_key')->all());
        if (!empty($validated['api_key'])) {
            $device->api_key = $validated['api_key'];
        }
        $device->save();

        return response()->json([
            'message' => 'Device updated',
            'data' => $this->formatDevice($device->fresh(['company', 'location'])),
        ]);
    }

    private function validatedLocationId(mixed $locationId, mixed $companyId): ?int
    {
        if ($locationId === null || $locationId === '' || (int) $locationId <= 0) {
            return null;
        }
        $loc = CompanyLocation::find((int) $locationId);
        if (!$loc) {
            return null;
        }
        if ($companyId && (int) $loc->company_id !== (int) $companyId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'location_id' => ['Branch / location must belong to the selected company.'],
            ]);
        }

        return (int) $loc->id;
    }

    public function destroy(int $id)
    {
        ZktecoAttendanceService::ensureTables();
        ZktecoDevice::findOrFail($id)->delete();

        return response()->json(['message' => 'Device removed']);
    }

    public function testConnection(int $id)
    {
        ZktecoAttendanceService::ensureTables();
        $device = ZktecoDevice::findOrFail($id);

        if ($device->isLiveU()) {
            $result = $this->liveUClient->testConnection($device);
            if ($result['ok'] ?? false) {
                $device->update(['last_error' => null, 'last_handshake_at' => now()]);
            } else {
                $device->update(['last_error' => $result['message'] ?? 'Test failed']);
            }

            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        $adms = $device->admsBaseUrl();
        $reachableHint = '';
        if (preg_match('#127\.0\.0\.1|localhost#i', $adms)) {
            $reachableHint = ' WARNING: ADMS URL still points at localhost — the K40 Pro cannot reach it. Set ZKTECO_LAN_HOST (or APP_URL) to this PC LAN IP (e.g. 192.168.1.147) and put that URL on the device.';
        }

        return response()->json([
            'ok' => true,
            'message' => 'ADMS mode: on the K40 Pro set Comm → Cloud Server / ADMS to '.$adms
                .' then reboot the device. After handshake, scan a finger — punches appear on Time Card.'
                .$reachableHint,
            'adms_url' => $adms,
            'device_ip' => $device->ip_address,
            'lan_host' => config('zkteco.lan_host') ?: ZktecoDevice::detectLanHost(),
        ]);
    }

    public function syncNow(int $id, Request $request)
    {
        ZktecoAttendanceService::ensureTables();
        $device = ZktecoDevice::findOrFail($id);

        if (!$device->isLiveU()) {
            // ADMS devices push; Sync Now only re-explains setup (no LAN pull if UDP 4370 closed).
            return response()->json([
                'message' => 'K40 Pro ADMS does not pull from the device IP. Existing punches on the terminal are uploaded only after Cloud Server points at HR. '
                    .'Set the device ADMS URL to '.$device->admsBaseUrl()
                    .' (same LAN as 192.168.1.x), wait for Last handshake, then scan once. '
                    .'"Recent punches" shows imported HR logs — empty means no ADMS push yet.',
                'adms_url' => $device->admsBaseUrl(),
                'data' => [
                    'ok' => false,
                    'mode' => 'adms',
                    'imported' => 0,
                    'skipped' => 0,
                ],
            ], 422);
        }

        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);

        $from = isset($validated['from_date'])
            ? Carbon::parse($validated['from_date'])->startOfDay()
            : now()->subDays(7)->startOfDay();
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])->endOfDay()
            : now();

        $result = $this->liveUAttendance->syncDevice($device, $from, $to);

        return response()->json([
            'message' => $result['message'] ?? 'Sync completed',
            'data' => $result,
        ], ($result['ok'] ?? false) ? 200 : 422);
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
            'connection_mode' => $device->connection_mode,
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
                        'title' => 'SenseFP M2 (LiveU cloud) — recommended for Live U devices',
                        'detail' => 'Add Device → Connection = LiveU / SenseFP M2. Enter Serial Number (e.g. FQQ2254700019), branch name, and Branch API key from Live U (x-api-key). Click Test, then Sync Now. LiveU memberId must = Attendance Employee No.',
                    ],
                    [
                        'title' => 'K40 Pro / ADMS push',
                        'detail' => "Add Device → Connection = ADMS. Set Cloud Server on the terminal to {$adms}. Device pushes ATTLOG; no Sync Now needed.",
                    ],
                    [
                        'title' => 'Employee IDs',
                        'detail' => 'On the terminal / LiveU members, User ID / memberId must match HR Attendance Employee No (same as Hikvision).',
                    ],
                ],
                'adms_url_example' => $adms,
                'liveu_base' => config('liveu.base_url'),
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
            'location_id' => $d->location_id,
            'location_name' => $d->location->name ?? null,
            'is_active' => $d->is_active,
            'adms_enabled' => $d->adms_enabled,
            'connection_mode' => $d->connection_mode ?? 'adms',
            'has_api_key' => !empty($d->api_key_encrypted),
            'adms_url' => $d->admsBaseUrl(),
            'punches_url' => $d->punchesUrl(),
            'liveu_base' => config('liveu.base_url'),
            'last_att_stamp' => $d->last_att_stamp,
            'last_sync_at' => $d->last_sync_at,
            'last_event_at' => $d->last_event_at,
            'last_handshake_at' => $d->last_handshake_at,
            'last_error' => $d->last_error,
            'online' => $d->last_handshake_at && $d->last_handshake_at->gt(now()->subMinutes(10)),
        ];
    }
}
