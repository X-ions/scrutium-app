<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustedDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_id',
        'fingerprint',
        'alias',
        'browser',
        'os',
        'device_type',
        'trusted_ip',
        'trusted_location_country',
        'trusted_location_city',
        'trust_method',
        'confirmation_token',
        'token_expires_at',
        'confirmed_at',
        'confirmed_by',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeConfirmed($query)
    {
        return $query->whereNotNull('confirmed_at');
    }

    public function scopePending($query)
    {
        return $query->whereNull('confirmed_at')
            ->whereNull('revoked_at')
            ->where('token_expires_at', '>', now());
    }

    public function scopeRevoked($query)
    {
        return $query->whereNotNull('revoked_at');
    }

    public function scopeValidToken($query)
    {
        return $query->where('token_expires_at', '>', now());
    }

    public function isConfirmed(): bool
    {
        return !is_null($this->confirmed_at);
    }

    public function isPending(): bool
    {
        return is_null($this->confirmed_at) 
            && is_null($this->revoked_at) 
            && $this->token_expires_at?->isFuture();
    }

    public function isRevoked(): bool
    {
        return !is_null($this->revoked_at);
    }

    public function isTokenValid(): bool
    {
        return $this->token_expires_at?->isFuture() ?? false;
    }

    public function confirm(int $userId): void
    {
        $this->update([
            'confirmed_at' => now(),
            'confirmed_by' => $userId,
            'confirmation_token' => null,
            'token_expires_at' => null,
        ]);
        
        $this->device?->markTrusted($userId, $this->trust_method);
    }

    public function revoke(int $userId, string $reason): void
    {
        $this->update([
            'revoked_at' => now(),
            'revoked_by' => $userId,
            'revoke_reason' => $reason,
        ]);
        
        $this->device?->revokeTrust($userId, $reason);
    }

    public function generateConfirmationToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->update([
            'confirmation_token' => $token,
            'token_expires_at' => now()->addDays(7),
        ]);
        return $token;
    }

    public function getDisplayName(): string
    {
        return $this->alias ?: $this->device?->getDisplayName() ?: 'Trusted Device';
    }
};