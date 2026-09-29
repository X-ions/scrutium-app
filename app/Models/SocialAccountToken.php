<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenantThroughRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OAuth credentials for a social account. Every token column is encrypted at rest.
 *
 * @property int $social_account_id
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class SocialAccountToken extends Model
{
    /** @use HasFactory<\Database\Factories\SocialAccountTokenFactory> */
    use HasFactory, ScopedToTenantThroughRelation;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'social_account_id',
        'access_token',
        'refresh_token',
        'id_token',
        'token_type',
        'expires_at',
        'scope',
        'metadata',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
        'id_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'id_token' => 'encrypted',
            'metadata' => 'encrypted:array',
            'expires_at' => 'datetime',
        ];
    }

    protected static function tenantScopedRelation(): string
    {
        return 'socialAccount';
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the token must be refreshed before the next provider call.
     */
    public function needsRefresh(): bool
    {
        return $this->expires_at !== null && $this->expires_at->subMinutes(5)->isFuture();
    }

    public function expiresSoon(): bool
    {
        return $this->expires_at !== null && $this->expires_at->between(now(), now()->addDay());
    }

    /**
     * Non-secret token metadata that is safe to display.
     *
     * @return array<string, mixed>
     */
    public function publicStatus(): array
    {
        return [
            'token_type' => $this->token_type,
            'scope' => $this->scope,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'has_refresh_token' => filled($this->refresh_token),
        ];
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    public function scopeExpiringSoon(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDay()]);
    }
}
