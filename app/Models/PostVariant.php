<?php

namespace App\Models;

use App\Enums\PostVariantStatus;
use App\Enums\SocialPlatform;
use App\Models\Concerns\ScopedToTenantThroughRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PostVariant extends Model
{
    /** @use HasFactory<\Database\Factories\PostVariantFactory> */
    use HasFactory, ScopedToTenantThroughRelation, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'social_account_id',
        'provider',
        'caption',
        'media_ids',
        'hashtags',
        'mentions',
        'location_id',
        'location_name',
        'platform_specific',
        'status',
        'provider_post_id',
        'provider_post_url',
        'error_message',
        'retry_count',
        'scheduled_at',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialPlatform::class,
            'status' => PostVariantStatus::class,
            'media_ids' => 'array',
            'hashtags' => 'array',
            'mentions' => 'array',
            'platform_specific' => 'array',
            'retry_count' => 'integer',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function tenantScopedRelation(): string
    {
        return 'post';
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'post_media')
            ->withPivot(['sort_order']);
    }

    public function scheduledPost(): HasOne
    {
        return $this->hasOne(ScheduledPost::class);
    }

    public function publishingAttempts(): HasMany
    {
        return $this->hasMany(PublishingAttempt::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(AnalyticsMetric::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(AnalyticsSnapshot::class);
    }

    public function statusEnum(): PostVariantStatus
    {
        return $this->status instanceof PostVariantStatus ? $this->status : PostVariantStatus::Pending;
    }

    public function isPublished(): bool
    {
        return $this->statusEnum() === PostVariantStatus::Published;
    }

    public function hasExhaustedRetries(int $maxRetries = 3): bool
    {
        return (int) $this->retry_count >= $maxRetries;
    }

    public function captionLength(): int
    {
        return mb_strlen((string) $this->caption);
    }

    public function scopeInStatus(Builder $query, PostVariantStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn (PostVariantStatus $status) => $status->value, $statuses));
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->inStatus(PostVariantStatus::Published);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->inStatus(PostVariantStatus::Failed);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->inStatus(PostVariantStatus::Pending, PostVariantStatus::Scheduled);
    }
}
