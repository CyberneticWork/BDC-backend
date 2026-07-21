<?php

namespace App\Http\Controllers;

use App\Models\HikvisionDevice;
use App\Models\HikvisionEventLog;
use App\Services\Hikvision\HikvisionAttendanceService;
use App\Services\Hikvision\HikvisionExcelImportService;
use App\Services\Hikvision\HikvisionIsapiClient;
use Illuminate\Http\Request;

class HikvisionController extends Controller
{
    public function __construct(
        private HikvisionIsapiClient $isapiClient,
        private HikvisionAttendanceService $attendanceService,
        private HikvisionExcelImportService $excelImportService,
    ) {
    }

    public function index()
    {
        $devices = HikvisionDevice::with('company:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (HikvisionDevice $d) => $this->formatDevice($d));

        return response()->json(['data' => $devices]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'model' => 'nullable|string|max:120',
            'serial_number' => 'nullable|string|max:120',
            'ip_address' => 'required|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'required|string|max:64',
            'password' => 'required|string|max:128',
            'company_id' => 'nullable|exists:companies,id',
            'is_active' => 'boolean',
            'webhook_enabled' => 'boolean',
            'polling_enabled' => 'boolean',
        ]);

        $device = new HikvisionDevice($validated);
        $device->password = $validated['password'];
        $device->port = $validated['port'] ?? 80;
        $device->save();

        return response()->json([
            'message' => 'Hikvision device saved',
            'data' => $this->formatDevice($device->fresh('company')),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $device = HikvisionDevice::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'model' => 'nullable|string|max:120',
            'serial_number' => 'nullable|string|max:120',
            'ip_address' => 'sometimes|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'sometimes|string|max:64',
            'password' => 'nullable|string|max:128',
            'company_id' => 'nullable|exists:companies,id',
            'is_active' => 'boolean',
            'webhook_enabled' => 'boolean',
            'polling_enabled' => 'boolean',
        ]);

        $device->fill($validated);

        if (!empty($validated['password'])) {
            $device->password = $validated['password'];
        }

        $device->save();

        return response()->json([
            'message' => 'Device updated',
            'data' => $this->formatDevice($device->fresh('company')),
        ]);
    }

    public function destroy(int $id)
    {
        HikvisionDevice::findOrFail($id)->delete();

        return response()->json(['message' => 'Device removed']);
    }

    public function testConnection(int $id)
    {
        $device = HikvisionDevice::findOrFail($id);
        $result = $this->isapiClient->testConnection($device);

        if (!$result['ok']) {
            $device->update(['last_error' => $result['message'] ?? 'Connection failed']);
            return response()->json($result, 422);
        }

        $device->update(['last_error' => null]);

        return response()->json($result);
    }

    public function syncNow(int $id, Request $request)
    {
        $device = HikvisionDevice::findOrFail($id);

        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);

        $from = isset($validated['from_date']) ? \Carbon\Carbon::parse($validated['from_date'])->startOfDay() : null;
        $to = isset($validated['to_date']) ? \Carbon\Carbon::parse($validated['to_date'])->endOfDay() : null;

        $result = $this->attendanceService->syncDevice($device, $from, $to);

        return response()->json([
            'message' => 'Sync completed',
            'data' => $result,
        ]);
    }

    public function configureWebhook(int $id)
    {
        $device = HikvisionDevice::findOrFail($id);
        $result = $this->isapiClient->configureWebhook($device);

        if (!$result['ok']) {
            return response()->json([
                'message' => $result['message'] ?? 'Failed to configure webhook on device',
                'webhook_url' => $device->webhookUrl(),
                'manual_setup_required' => true,
            ], 422);
        }

        return response()->json([
            'message' => 'Webhook configured on device',
            'webhook_url' => $device->webhookUrl(),
        ]);
    }

    public function webhook(Request $request, string $token)
    {
        $device = HikvisionDevice::where('webhook_token', $token)->first();

        if (!$device) {
            return response()->json(['message' => 'Unknown device'], 404);
        }

        $secret = config('hikvision.webhook_secret');
        if ($secret && $request->header('X-Hikvision-Secret') !== $secret) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $result = $this->attendanceService->handleWebhook($device, $request);

        return response()->json(['message' => 'OK', 'data' => $result]);
    }

    public function eventLogs(int $id, Request $request)
    {
        $device = HikvisionDevice::findOrFail($id);

        $logs = HikvisionEventLog::where('device_id', $device->id)
            ->orderByDesc('event_time')
            ->limit((int) $request->query('limit', 50))
            ->get();

        return response()->json(['data' => $logs]);
    }

    public function importExcel(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'company_id' => 'required|exists:companies,id',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $path = $request->file('file')->getRealPath();
        $result = $this->excelImportService->import(
            $path,
            (int) $validated['company_id'],
            $validated['from_date'],
            $validated['to_date'] ?? null
        );

        return response()->json([
            'message' => 'Import completed',
            'data' => $result,
        ]);
    }

    private function formatDevice(HikvisionDevice $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'model' => $device->model,
            'serial_number' => $device->serial_number,
            'ip_address' => $device->ip_address,
            'port' => $device->port,
            'username' => $device->username,
            'company_id' => $device->company_id,
            'company_name' => $device->company->name ?? null,
            'is_active' => $device->is_active,
            'webhook_enabled' => $device->webhook_enabled,
            'polling_enabled' => $device->polling_enabled,
            'webhook_url' => $device->webhookUrl(),
            'last_sync_at' => $device->last_sync_at,
            'last_event_at' => $device->last_event_at,
            'last_error' => $device->last_error,
        ];
    }
}
