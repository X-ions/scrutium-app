<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fingerprint',
        'browser',
        'browser_version',
        'os',
        'os_version',
        'device_type',
        'device_brand',
        'device_model',
        'fingerprint_components',
        'first_ip',
        'first_location_country',
        'first_location_city',
        'first_seen_at',
        'last_seen_at',
        'login_count',
        'is_trusted',
        'trusted_at',
        'trusted_by',
        'trust_method',
        'is_blocked',
        'blocked_at',
        'blocked_by',
        'block_reason',
    ];

    protected $casts = [
        'fingerprint_components' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'trusted_at' => 'datetime',
        'blocked_at' => 'datetime',
        'is_trusted' => 'boolean',
        'is_blocked' => 'boolean',
        'login_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trusted_by');
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function trustedDevice(): HasOne
    {
        return $this->hasOne(TrustedDevice::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeTrusted($query)
    {
        return $query->where('is_trusted', true);
    }

    public function scopeUntrusted($query)
    {
        return $query->where('is_trusted', false);
    }

    public function scopeActive($query, int $days = 30)
    {
        return $query->where('last_seen_at', '>=', now()->subDays($days));
    }

    public function scopeBlocked($query)
    {
        return $query->where('is_blocked', true);
    }

    public function recordLogin(string $ip, ?string $country = null, ?string $city = null): void
    {
        $this->increment('login_count');

        $this->forceFill([
            'last_seen_at' => now(),
            'first_ip' => $this->first_ip ?: $ip,
            'first_location_country' => $this->first_location_country ?: $country,
            'first_location_city' => $this->first_location_city ?: $city,
        ])->save();
    }

    public function markTrusted(int $userId, string $method): void
    {
        $this->update([
            'is_trusted' => true,
            'trusted_at' => now(),
            'trusted_by' => $userId,
            'trust_method' => $method,
        ]);
    }

    public function block(int $userId, string $reason): void
    {
        $this->update([
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_by' => $userId,
            'block_reason' => $reason,
        ]);
    }

    public function unblock(): void
    {
        $this->update([
            'is_blocked' => false,
            'blocked_at' => null,
            'blocked_by' => null,
            'block_reason' => null,
        ]);
    }

    public function revokeTrust(int $userId, string $reason): void
    {
        $this->update([
            'is_trusted' => false,
            'trusted_at' => null,
            'trusted_by' => null,
            'trust_method' => null,
        ]);

        $this->trustedDevice?->update([
            'revoked_at' => now(),
            'revoked_by' => $userId,
            'revoke_reason' => $reason,
        ]);
    }

    public function getDisplayName(): string
    {
        $parts = [];
        if ($this->device_brand && $this->device_model) {
            $parts[] = "{$this->device_brand} {$this->device_model}";
        } elseif ($this->device_type) {
            $parts[] = ucfirst($this->device_type);
        }
        if ($this->browser) {
            $parts[] = $this->browser.($this->browser_version ? " {$this->browser_version}" : '');
        }
        if ($this->os) {
            $parts[] = $this->os.($this->os_version ? " {$this->os_version}" : '');
        }

        return $parts ? implode(' • ', $parts) : 'Unknown Device';
    }

    public function getShortDisplayName(): string
    {
        if ($this->device_brand && $this->device_model) {
            return "{$this->device_brand} {$this->device_model}";
        }
        if ($this->device_type) {
            return ucfirst($this->device_type);
        }
        if ($this->browser) {
            return $this->browser;
        }

        return 'Unknown Device';
    }
}
