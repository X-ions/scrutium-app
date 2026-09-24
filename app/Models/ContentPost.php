<?php

namespace App\Models;

use App\Enums\Platform;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPost extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Provenance score below which a post is treated as unverified.
     */
    public const PROVENANCE_THRESHOLD = 70;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'influencer_id',
        'deliverable_id',
        'platform',
        'external_id',
        'url',
        'caption',
        'posted_at',
        'impressions',
        'reach',
        'likes',
        'comments',
        'shares',
        'saves',
        'engagement_rate',
        'provenance_score',
        'has_disclosure',
        'has_captions',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'posted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'impressions' => 'integer',
            'reach' => 'integer',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'saves' => 'integer',
            'engagement_rate' => 'decimal:2',
            'provenance_score' => 'decimal:2',
            'has_disclosure' => 'boolean',
            'has_captions' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    public function totalEngagements(): int
    {
        return (int) $this->likes
            + (int) $this->comments
            + (int) $this->shares
            + (int) $this->saves;
    }

    /**
     * True when the post breaches a system-integrity rule.
     */
    public function isFlagged(): bool
    {
        if (! $this->has_disclosure || ! $this->has_captions) {
            return true;
        }

        if ($this->provenance_score === null) {
            return true;
        }

        return (float) $this->provenance_score < self::PROVENANCE_THRESHOLD;
    }

    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('has_disclosure', false)
                ->orWhere('has_captions', false)
                ->orWhereNull('provenance_score')
                ->orWhere('provenance_score', '<', self::PROVENANCE_THRESHOLD);
        });
    }

    public function scopeOnPlatform(Builder $query, Platform $platform): Builder
    {
        return $query->where('platform', $platform->value);
    }

    /**
     * Engagement rate expressed as a percentage of reach.
     */
    public function computedEngagementRate(): float
    {
        $reach = (int) $this->reach;

        if ($reach <= 0) {
            return 0.0;
        }

        return round(($this->totalEngagements() / $reach) * 100, 2);
    }
}
