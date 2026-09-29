<?php

namespace Database\Factories;

use App\Enums\MetricType;
use App\Enums\SocialPlatform;
use App\Models\AnalyticsMetric;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsMetric>
 */
class AnalyticsMetricFactory extends Factory
{
    protected $model = AnalyticsMetric::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'social_account_id' => fn () => SocialAccount::factory()->create()->id,
            'post_variant_id' => null,
            'provider' => fake()->randomElement(SocialPlatform::cases()),
            'metric_type' => fake()->randomElement(MetricType::cases()),
            'metric_subtype' => null,
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
            'value' => fake()->numberBetween(0, 10_000),
            'raw_response' => null,
            'recorded_at' => now(),
        ];
    }

    public function ofType(MetricType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'metric_type' => $type,
        ]);
    }

    public function forVariant(PostVariant $variant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $variant->post->tenant_id,
            'social_account_id' => $variant->social_account_id,
            'post_variant_id' => $variant->getKey(),
            'provider' => $variant->provider,
        ]);
    }

    public function accountLevel(): static
    {
        return $this->state(fn (array $attributes) => [
            'post_variant_id' => null,
        ]);
    }

    public function between(\DateTimeInterface $start, \DateTimeInterface $end): static
    {
        return $this->state(fn (array $attributes) => [
            'period_start' => $start,
            'period_end' => $end,
        ]);
    }
}
