<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_id',
        'session_id',
        'ip_address',
        'user_agent',
        'browser',
        'os',
        'device_type',
        'location_country',
        'location_city',
        'location_lat',
        'location_lon',
        'is_current',
        'is_revoked',
        'started_at',
        'last_activity_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'is_current' => 'boolean',
        'is_revoked' => 'boolean',
        'location_lat' => 'decimal:7',
        'location_lon' => 'decimal:7',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_revoked', false)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeRevoked($query)
    {
        return $query->where('is_revoked', true);
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeStale($query, int $hours = 24)
    {
        return $query->where('last_activity_at', '<', now()->subHours($hours));
    }

    public function isActive(): bool
    {
        return ! $this->is_revoked
            && ($this->expires_at?->isFuture() ?? true);
    }

    public function isCurrent(): bool
    {
        return $this->is_current;
    }

    public function revoke(int $userId, string $reason): void
    {
        $this->update([
            'is_revoked' => true,
            'is_current' => false,
            'revoked_at' => now(),
            'revoked_by' => $userId,
            'revoke_reason' => $reason,
        ]);
    }

    public function markCurrent(): void
    {
        $this->update([
            'is_current' => true,
            'last_activity_at' => now(),
        ]);
    }

    public function updateActivity(): void
    {
        $this->update([
            'last_activity_at' => now(),
        ]);
    }

    public function getDuration(): string
    {
        return $this->started_at->diffForHumans();
    }

    public function getDisplayName(): string
    {
        $parts = [];
        if ($this->device_type) {
            $parts[] = ucfirst($this->device_type);
        }
        if ($this->browser) {
            $parts[] = $this->browser;
        }
        if ($this->os) {
            $parts[] = $this->os;
        }
        if ($this->location_city && $this->location_country) {
            $parts[] = "{$this->location_city}, {$this->location_country}";
        } elseif ($this->location_country) {
            $parts[] = $this->location_country;
        }

        return $parts ? implode(' • ', $parts) : 'Active Session';
    }
}
