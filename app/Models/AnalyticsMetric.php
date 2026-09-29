<?php

namespace App\Models;

use App\Enums\MetricType;
use App\Enums\SocialPlatform;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsMetric extends Model
{
    /** @use HasFactory<\Database\Factories\AnalyticsMetricFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'social_account_id',
        'post_variant_id',
        'provider',
        'metric_type',
        'metric_subtype',
        'period_start',
        'period_end',
        'value',
        'raw_response',
        'recorded_at',
    ];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialPlatform::class,
            'metric_type' => MetricType::class,
            'raw_response' => 'array',
            'value' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function postVariant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class);
    }

    public function isAccountLevel(): bool
    {
        return $this->post_variant_id === null;
    }

    public function scopeOfType(Builder $query, MetricType $type): Builder
    {
        return $query->where('metric_type', $type->value);
    }

    public function scopeAccountLevel(Builder $query): Builder
    {
        return $query->whereNull('post_variant_id');
    }

    public function scopeInPeriod(Builder $query, string $from, string $until): Builder
    {
        return $query->whereBetween('period_start', [$from, $until]);
    }

    public function scopeRecordedBefore(Builder $query, string $date): Builder
    {
        return $query->where('period_start', '<', $date);
    }
}
