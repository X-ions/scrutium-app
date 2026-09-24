<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoreConfig extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Metric weights used when a config does not define its own.
     *
     * @var array<string, float>
     */
    public const DEFAULT_WEIGHTS = [
        'engagement_rate' => 0.30,
        'audience_quality' => 0.25,
        'content_relevance' => 0.20,
        'reliability' => 0.15,
        'cost_efficiency' => 0.10,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'weights',
        'cohort_rules',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weights' => 'array',
            'cohort_rules' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function scores(): HasMany
    {
        return $this->hasMany(InfluencerScore::class);
    }

    /**
     * @return array<string, float>
     */
    public function weightMap(): array
    {
        $weights = $this->weights;

        return is_array($weights) && $weights !== [] ? $weights : self::DEFAULT_WEIGHTS;
    }

    public function weightFor(string $metric): float
    {
        return (float) ($this->weightMap()[$metric] ?? 0.0);
    }

    /**
     * Weights should sum to 1.0; this reports how far off a config is.
     */
    public function weightTotal(): float
    {
        return round(array_sum($this->weightMap()), 4);
    }

    public function isBalanced(): bool
    {
        return abs($this->weightTotal() - 1.0) < 0.0001;
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
