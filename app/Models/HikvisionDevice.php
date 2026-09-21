<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class HikvisionDevice extends Model
{
    protected $fillable = [
        'name',
        'model',
        'serial_number',
        'ip_address',
        'port',
        'username',
        'password_encrypted',
        'company_id',
        'is_active',
        'webhook_enabled',
        'polling_enabled',
        'webhook_token',
        'last_serial_no',
        'last_sync_at',
        'last_event_at',
        'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'webhook_enabled' => 'boolean',
        'polling_enabled' => 'boolean',
        'last_serial_no' => 'integer',
        'last_sync_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    protected $hidden = [
        'password_encrypted',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            if (empty($device->webhook_token)) {
                $device->webhook_token = Str::random(48);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(company::class);
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(HikvisionEventLog::class, 'device_id');
    }

    public function setPasswordAttribute(?string $password): void
    {
        if ($password !== null && $password !== '') {
            $this->attributes['password_encrypted'] = Crypt::encryptString($password);
        }
    }

    public function getPasswordAttribute(): ?string
    {
        if (empty($this->password_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->password_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function baseUrl(): string
    {
        $scheme = $this->port === 443 ? 'https' : 'http';

        return sprintf('%s://%s:%d', $scheme, $this->ip_address, $this->port);
    }

    public static function publicApiBase(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function webhookUrl(): string
    {
        return self::publicApiBase() . '/api/hikvision/webhook/' . $this->webhook_token;
    }

    public function punchesUrl(): string
    {
        $base = self::publicApiBase();
        if (preg_match('#/index\.php$#i', $base)) {
            return $base . '/api/hikvision/punches/' . $this->webhook_token;
        }

        return $base . '/api/hikvision/punches/' . $this->webhook_token;
    }
}
