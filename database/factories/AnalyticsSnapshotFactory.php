<?php

namespace Database\Factories;

use App\Models\AnalyticsSnapshot;
use App\Models\SocialAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsSnapshot>
 */
class AnalyticsSnapshotFactory extends Factory
{
    protected $model = AnalyticsSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'social_account_id' => fn () => SocialAccount::factory()->create()->id,
            'post_variant_id' => null,
            'provider' => null,
            'date' => now()->toDateString(),
            'period' => 'daily',
            'metrics' => [
                'views' => fake()->numberBetween(0, 50_000),
                'likes' => fake()->numberBetween(0, 2_000),
                'comments' => fake()->numberBetween(0, 200),
                'shares' => fake()->numberBetween(0, 300),
            ],
        ];
    }

    public function tenantLevel(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
            'social_account_id' => null,
            'post_variant_id' => null,
            'provider' => null,
        ]);
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes) => [
            'period' => $period,
        ]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }
}
