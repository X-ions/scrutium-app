<?php

namespace App\Models;

use App\Enums\ScheduledPostStatus;
use App\Models\Concerns\ScopedToTenantThroughRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledPost extends Model
{
    /** @use HasFactory<\Database\Factories\ScheduledPostFactory> */
    use HasFactory, ScopedToTenantThroughRelation;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_variant_id',
        'scheduled_at',
        'timezone',
        'status',
        'job_id',
        'attempts',
        'max_attempts',
        'last_error',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScheduledPostStatus::class,
            'scheduled_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    protected static function tenantScopedRelation(): string
    {
        return 'postVariant.post';
    }

    public function postVariant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class);
    }

    public function publishingAttempts(): HasMany
    {
        return $this->hasMany(PublishingAttempt::class);
    }

    public function statusEnum(): ScheduledPostStatus
    {
        return $this->status instanceof ScheduledPostStatus ? $this->status : ScheduledPostStatus::Pending;
    }

    public function hasAttemptsRemaining(): bool
    {
        return (int) $this->attempts < (int) $this->max_attempts;
    }

    public function scopeReadyToRun(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ScheduledPostStatus::Pending->value,
            ScheduledPostStatus::Queued->value,
        ])->where('scheduled_at', '<=', now());
    }

    public function scopeBetween(Builder $query, string $from, string $until): Builder
    {
        return $query->whereBetween('scheduled_at', [$from, $until]);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ScheduledPostStatus::Failed->value);
    }
}
