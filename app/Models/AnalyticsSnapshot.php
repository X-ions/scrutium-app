<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\AnalyticsSnapshotFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Aggregated counters consumed by dashboard widgets.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'social_account_id',
        'post_variant_id',
        'provider',
        'date',
        'period',
        'metrics',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialPlatform::class,
            'date' => 'date',
            'metrics' => 'array',
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

    public function isTenantLevel(): bool
    {
        return $this->social_account_id === null;
    }

    public function valueFor(string $metric, int $default = 0): int
    {
        $metrics = $this->metrics;

        if (! is_array($metrics)) {
            return $default;
        }

        return (int) ($metrics[$metric] ?? $default);
    }

    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }

    public function scopeBetweenDates(Builder $query, string $from, string $until): Builder
    {
        return $query->whereBetween('date', [$from, $until]);
    }

    public function scopeTenantLevel(Builder $query): Builder
    {
        return $query->whereNull('social_account_id')->whereNull('post_variant_id');
    }
}
