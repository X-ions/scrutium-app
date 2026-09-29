<?php

namespace App\Models;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $tenant_id
 * @property SocialPlatform $provider
 * @property SocialAccountStatus $status
 */
class SocialAccount extends Model
{
    /** @use HasFactory<\Database\Factories\SocialAccountFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'provider',
        'provider_account_id',
        'provider_username',
        'provider_display_name',
        'provider_avatar_url',
        'account_type',
        'status',
        'permissions',
        'capabilities',
        'is_default',
        'last_synced_at',
        'last_error',
        'metadata',
    ];

    /**
     * The token relation must never reach a response, log or queue payload.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
        'permissions',
        'capabilities',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialPlatform::class,
            'status' => SocialAccountStatus::class,
            'permissions' => 'array',
            'capabilities' => 'array',
            'metadata' => 'array',
            'is_default' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function token(): HasOne
    {
        return $this->hasOne(SocialAccountToken::class);
    }

    public function postVariants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(AnalyticsMetric::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(AnalyticsSnapshot::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function commentReplies(): HasMany
    {
        return $this->hasMany(CommentReply::class);
    }

    public function statusEnum(): SocialAccountStatus
    {
        return $this->status instanceof SocialAccountStatus
            ? $this->status
            : SocialAccountStatus::Disconnected;
    }

    public function isActive(): bool
    {
        return $this->statusEnum()->isActive();
    }

    public function hasUsableToken(): bool
    {
        $expiresAt = $this->token?->expires_at;

        return $expiresAt === null || $expiresAt->isFuture();
    }

    /**
     * Credential-free view of the account for UI and API responses.
     *
     * @return array<string, mixed>
     */
    public function publicStatus(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider?->value,
            'provider_label' => $this->provider?->label(),
            'provider_account_id' => $this->provider_account_id,
            'provider_username' => $this->provider_username,
            'provider_display_name' => $this->provider_display_name,
            'provider_avatar_url' => $this->provider_avatar_url,
            'account_type' => $this->account_type,
            'status' => $this->statusEnum()->value,
            'status_label' => $this->statusEnum()->label(),
            'is_default' => (bool) $this->is_default,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'token_expires_at' => $this->token?->expires_at?->toIso8601String(),
            'has_token' => $this->token !== null,
        ];
    }

    public function markConnected(): self
    {
        $this->status = SocialAccountStatus::Connected;
        $this->last_error = null;
        $this->last_synced_at = now();
        $this->save();

        return $this;
    }

    public function markStatus(SocialAccountStatus $status, ?string $error = null): self
    {
        $this->status = $status;
        $this->last_error = $error;
        $this->save();

        return $this;
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', SocialAccountStatus::Connected->value);
    }

    public function scopeForProvider(Builder $query, SocialPlatform $provider): Builder
    {
        return $query->where('provider', $provider->value);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SocialAccountStatus::Expired->value,
            SocialAccountStatus::Revoked->value,
            SocialAccountStatus::Error->value,
        ]);
    }
}
