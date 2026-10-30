<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ZktecoDevice extends Model
{
    protected $fillable = [
        'name',
        'model',
        'serial_number',
        'ip_address',
        'port',
        'company_id',
        'is_active',
        'adms_enabled',
        'connection_mode',
        'api_key_encrypted',
        'webhook_token',
        'last_att_stamp',
        'last_op_stamp',
        'last_sync_at',
        'last_event_at',
        'last_handshake_at',
        'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'adms_enabled' => 'boolean',
        'last_sync_at' => 'datetime',
        'last_event_at' => 'datetime',
        'last_handshake_at' => 'datetime',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            if (empty($device->webhook_token)) {
                $device->webhook_token = Str::random(48);
            }
            if (empty($device->model)) {
                $device->model = ($device->connection_mode ?? '') === 'liveu' ? 'SenseFP M2' : 'K40 Pro';
            }
            if (empty($device->connection_mode)) {
                $device->connection_mode = 'adms';
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(company::class);
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(ZktecoEventLog::class, 'device_id');
    }

    public function setApiKeyAttribute(?string $key): void
    {
        if ($key !== null && $key !== '') {
            $this->attributes['api_key_encrypted'] = Crypt::encryptString($key);
        }
    }

    public function getApiKeyAttribute(): ?string
    {
        if (empty($this->api_key_encrypted)) {
            return null;
        }
        try {
            return Crypt::decryptString($this->api_key_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isLiveU(): bool
    {
        return ($this->connection_mode ?? 'adms') === 'liveu';
    }

    public static function publicApiBase(): string
    {
        return HikvisionDevice::publicApiBase();
    }

    public static function frontControllerUrl(string $apiPath): string
    {
        return HikvisionDevice::frontControllerUrl($apiPath);
    }

    public function admsBaseUrl(): string
    {
        $base = rtrim(self::publicApiBase(), '/');
        if (preg_match('#/index\.php$#i', $base)) {
            $origin = preg_replace('#/index\.php$#i', '', $base);

            return $origin.'/iclock';
        }

        return preg_replace('#/api$#i', '', $base).'/iclock';
    }

    public function punchesUrl(): string
    {
        return self::frontControllerUrl('/api/zkteco/punches/'.$this->webhook_token);
    }
}
