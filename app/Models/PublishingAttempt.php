<?php

namespace App\Models;

use App\Enums\PublishingAttemptStatus;
use App\Models\Concerns\ScopedToTenantThroughRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider call recorded for a variant. Payloads are sanitized before they land here.
 */
class PublishingAttempt extends Model
{
    /** @use HasFactory<\Database\Factories\PublishingAttemptFactory> */
    use HasFactory, ScopedToTenantThroughRelation;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_variant_id',
        'scheduled_post_id',
        'attempt_number',
        'status',
        'request_payload',
        'response_payload',
        'error_code',
        'error_message',
        'rate_limit_reset_at',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PublishingAttemptStatus::class,
            'attempt_number' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'rate_limit_reset_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function scheduledPost(): BelongsTo
    {
        return $this->belongsTo(ScheduledPost::class);
    }

    public function statusEnum(): PublishingAttemptStatus
    {
        return $this->status instanceof PublishingAttemptStatus
            ? $this->status
            : PublishingAttemptStatus::Pending;
    }

    public function durationInSeconds(): ?float
    {
        if ($this->started_at === null || $this->completed_at === null) {
            return null;
        }

        return round($this->started_at->diffInMilliseconds($this->completed_at) / 1000, 3);
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', PublishingAttemptStatus::Success->value);
    }

    public function scopeFailures(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PublishingAttemptStatus::Failed->value,
            PublishingAttemptStatus::RateLimited->value,
        ]);
    }

    public function scopeRateLimited(Builder $query): Builder
    {
        return $query->where('status', PublishingAttemptStatus::RateLimited->value);
    }
}
