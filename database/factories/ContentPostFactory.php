<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\ContentPost;
use App\Models\Influencer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPost>
 */
class ContentPostFactory extends Factory
{
    protected $model = ContentPost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reach = fake()->numberBetween(2_000, 500_000);

        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'campaign_id' => null,
            'influencer_id' => fn (array $attributes) => Influencer::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'deliverable_id' => null,
            'platform' => fake()->randomElement(Platform::cases())->value,
            'external_id' => fake()->unique()->numerify('post_##########'),
            'url' => 'https://example.com/p/'.fake()->unique()->slug(2),
            'caption' => fake()->sentence(12),
            'posted_at' => fake()->dateTimeBetween('-2 months', 'now'),
            'impressions' => fn (array $attributes) => (int) round($attributes['reach'] * fake()->randomFloat(2, 1, 1.8)),
            'reach' => $reach,
            'likes' => (int) round($reach * fake()->randomFloat(4, 0.01, 0.12)),
            'comments' => (int) round($reach * fake()->randomFloat(4, 0.001, 0.02)),
            'shares' => (int) round($reach * fake()->randomFloat(4, 0.0005, 0.01)),
            'saves' => (int) round($reach * fake()->randomFloat(4, 0.0005, 0.015)),
            'engagement_rate' => fake()->randomFloat(2, 0.5, 9.5),
            'provenance_score' => fake()->randomFloat(2, 45, 99),
            'has_disclosure' => true,
            'has_captions' => true,
            'last_synced_at' => now(),
        ];
    }

    public function flagged(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_disclosure' => false,
            'has_captions' => false,
            'provenance_score' => fake()->randomFloat(2, 20, 60),
        ]);
    }
}
