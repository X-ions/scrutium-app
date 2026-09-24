<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InfluencerScore extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'influencer_id',
        'campaign_id',
        'score_config_id',
        'score',
        'components',
        'computed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'components' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scoreConfig(): BelongsTo
    {
        return $this->belongsTo(ScoreConfig::class);
    }

    /**
     * Contribution of a single weighted component.
     */
    public function componentValue(string $metric): float
    {
        $components = $this->components;

        if (! is_array($components)) {
            return 0.0;
        }

        return (float) ($components[$metric] ?? 0.0);
    }

    public function band(): string
    {
        return match (true) {
            (float) $this->score >= 85 => 'Elite',
            (float) $this->score >= 70 => 'Strong',
            (float) $this->score >= 55 => 'Fair',
            default => 'At risk',
        };
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('computed_at');
    }
}
