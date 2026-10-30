<?php

namespace App\Services\Zkteco;

use App\Http\Controllers\TimeCardController;
use App\Models\ZktecoDevice;
use App\Models\ZktecoEventLog;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ZKTeco K40 Pro → time_cards (same attendance writer as Hikvision).
 */
class ZktecoAttendanceService
{
    public function __construct(private ZktecoAdmsParser $parser)
    {
    }

    public static function ensureTables(): void
    {
        if (!Schema::hasTable('zkteco_devices')) {
            Schema::create('zkteco_devices', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('model')->nullable()->default('K40 Pro');
                $table->string('serial_number')->unique();
                $table->string('ip_address')->nullable();
                $table->unsignedSmallInteger('port')->default(4370);
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->boolean('adms_enabled')->default(true);
                $table->string('connection_mode', 20)->default('adms');
                $table->text('api_key_encrypted')->nullable();
                $table->string('webhook_token', 64)->unique();
                $table->string('last_att_stamp', 40)->nullable();
                $table->string('last_op_stamp', 40)->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamp('last_event_at')->nullable();
                $table->timestamp('last_handshake_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }

        // LiveU / SenseFP M2 columns (self-heal on older installs)
        if (!Schema::hasColumn('zkteco_devices', 'connection_mode')) {
            Schema::table('zkteco_devices', function (Blueprint $table) {
                $table->string('connection_mode', 20)->default('adms')->after('adms_enabled');
            });
        }
        if (!Schema::hasColumn('zkteco_devices', 'api_key_encrypted')) {
            Schema::table('zkteco_devices', function (Blueprint $table) {
                $table->text('api_key_encrypted')->nullable()->after('connection_mode');
            });
        }

        if (!Schema::hasTable('zkteco_event_logs')) {
            Schema::create('zkteco_event_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('device_id')->constrained('zkteco_devices')->cascadeOnDelete();
                $table->unsignedBigInteger('serial_no')->nullable();
                $table->string('external_id', 64)->nullable()->index();
                $table->string('employee_no')->nullable();
                $table->timestamp('event_time');
                $table->string('source', 20);
                $table->unsignedBigInteger('time_card_id')->nullable();
                $table->string('status', 30)->default('processed');
                $table->text('message')->nullable();
                $table->timestamps();

                $table->index(['device_id', 'serial_no', 'event_time']);
                $table->index(['device_id', 'event_time']);
            });
        } elseif (!Schema::hasColumn('zkteco_event_logs', 'external_id')) {
            Schema::table('zkteco_event_logs', function (Blueprint $table) {
                $table->string('external_id', 64)->nullable()->after('serial_no')->index();
            });
        }
    }

    public function findBySerial(?string $sn): ?ZktecoDevice
    {
        $sn = trim((string) $sn);
        if ($sn === '') {
            return null;
        }

        return ZktecoDevice::where('serial_number', $sn)->first()
            ?? ZktecoDevice::whereRaw('UPPER(serial_number) = ?', [strtoupper($sn)])->first();
    }

    /** ADMS options body the K40 Pro expects after handshake. */
    public function optionsResponse(ZktecoDevice $device): string
    {
        $sn = $device->serial_number;
        $att = $device->last_att_stamp ?: '0';
        $op = $device->last_op_stamp ?: '0';

        $lines = [
            "GET OPTION FROM: {$sn}",
            "Stamp={$att}",
            "OpStamp={$op}",
            'ErrorDelay=60',
            'Delay=30',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=1111000000',
            'TimeZone=5.5',
            'Realtime=1',
            'Encrypt=0',
        ];

        return implode("\n", $lines)."\n";
    }

    public function importAttLog(ZktecoDevice $device, string $body, ?string $stamp = null): array
    {
        $events = $this->parser->parseAttLogBody($body);

        return $this->importParsedEvents($device, $events, 'adms', $stamp);
    }

    public function importAgentPunches(ZktecoDevice $device, array $payload): array
    {
        $events = $this->parser->parseJsonPunches($payload);

        return $this->importParsedEvents($device, $events, 'bridge');
    }

    private function importParsedEvents(ZktecoDevice $device, array $events, string $source, ?string $stamp = null): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($events as $parsed) {
            if (!$parsed) {
                $skipped++;
                continue;
            }

            $outcome = $this->processPunch($device, $parsed, $source);
            if ($outcome['status'] === 'imported') {
                $imported++;
            } else {
                $skipped++;
                if ($outcome['status'] === 'error' && !empty($outcome['message'])) {
                    $errors[] = $outcome['message'].' (device ID '.($parsed['employee_no'] ?? '?').')';
                }
            }
        }

        $errors = array_values(array_unique($errors));
        $updates = [
            'last_event_at' => $imported > 0 ? now() : $device->last_event_at,
            'last_sync_at' => now(),
            'last_error' => empty($errors) ? null : implode('; ', array_slice($errors, 0, 3)),
        ];
        if ($stamp !== null && $stamp !== '') {
            $updates['last_att_stamp'] = substr((string) $stamp, 0, 40);
        }
        $device->update($updates);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 50),
            'ok_count' => $imported + $skipped,
        ];
    }

    public function processPunch(ZktecoDevice $device, array $parsed, string $source): array
    {
        $eventAt = $parsed['event_at'] instanceof Carbon
            ? $parsed['event_at']
            : Carbon::parse($parsed['event_at']);

        if (!empty($parsed['external_id'])) {
            $dupExt = ZktecoEventLog::where('device_id', $device->id)
                ->where('external_id', $parsed['external_id'])
                ->where('status', '!=', 'error')
                ->exists();
            if ($dupExt) {
                return ['status' => 'duplicate', 'message' => 'LiveU event already processed'];
            }
        }

        if (!empty($parsed['serial_no'])) {
            $dup = ZktecoEventLog::where('device_id', $device->id)
                ->where('serial_no', $parsed['serial_no'])
                ->where('event_time', $eventAt)
                ->where('status', '!=', 'error')
                ->exists();
            if ($dup) {
                return ['status' => 'duplicate', 'message' => 'Event already processed'];
            }
        }

        $recent = ZktecoEventLog::where('device_id', $device->id)
            ->where('employee_no', $parsed['employee_no'])
            ->where('status', 'imported')
            ->whereBetween('event_time', [
                $eventAt->copy()->subSeconds(5),
                $eventAt->copy()->addSeconds(5),
            ])
            ->exists();
        if ($recent) {
            return ['status' => 'duplicate', 'message' => 'Duplicate within 5 seconds'];
        }

        $request = Request::create('/api/attendance', 'POST', [
            'empno' => $parsed['employee_no'],
            'date' => $parsed['date'],
            'time' => $parsed['time'],
        ]);

        try {
            $response = app(TimeCardController::class)->attendance($request);
            $statusCode = $response->getStatusCode();
            $payload = json_decode($response->getContent(), true);

            $externalId = $parsed['external_id'] ?? null;

            if ($statusCode === 201) {
                $timeCardId = $payload['data']['id'] ?? null;
                ZktecoEventLog::create([
                    'device_id' => $device->id,
                    'serial_no' => $parsed['serial_no'],
                    'external_id' => $externalId,
                    'employee_no' => $parsed['employee_no'],
                    'event_time' => $eventAt,
                    'source' => $source,
                    'time_card_id' => $timeCardId,
                    'status' => 'imported',
                    'message' => $payload['message'] ?? null,
                ]);
                $device->update(['last_event_at' => now()]);

                return ['status' => 'imported', 'time_card_id' => $timeCardId];
            }

            if ($statusCode === 409) {
                ZktecoEventLog::create([
                    'device_id' => $device->id,
                    'serial_no' => $parsed['serial_no'],
                    'external_id' => $externalId,
                    'employee_no' => $parsed['employee_no'],
                    'event_time' => $eventAt,
                    'source' => $source,
                    'status' => 'skipped',
                    'message' => $payload['message'] ?? 'Duplicate within 5 minutes',
                ]);

                return ['status' => 'duplicate', 'message' => $payload['message'] ?? null];
            }

            $message = $payload['message'] ?? ($payload['errors'] ?? 'Attendance rejected');
            ZktecoEventLog::create([
                'device_id' => $device->id,
                'serial_no' => $parsed['serial_no'],
                'external_id' => $externalId,
                'employee_no' => $parsed['employee_no'],
                'event_time' => $eventAt,
                'source' => $source,
                'status' => 'error',
                'message' => is_array($message) ? json_encode($message) : $message,
            ]);

            Log::info('ZKTeco punch rejected', [
                'device_id' => $device->id,
                'employee_no' => $parsed['employee_no'],
                'message' => $message,
            ]);

            return ['status' => 'error', 'message' => is_array($message) ? json_encode($message) : $message];
        } catch (\Throwable $e) {
            Log::error('ZKTeco punch failed', [
                'device_id' => $device->id,
                'employee_no' => $parsed['employee_no'],
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
