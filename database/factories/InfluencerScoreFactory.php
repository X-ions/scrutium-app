<?php

namespace Database\Factories;

use App\Models\Influencer;
use App\Models\InfluencerScore;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InfluencerScore>
 */
class InfluencerScoreFactory extends Factory
{
    protected $model = InfluencerScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'influencer_id' => fn (array $attributes) => Influencer::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'campaign_id' => null,
            'score_config_id' => null,
            'score' => fake()->randomFloat(2, 40, 99),
            'components' => [
                'engagement_rate' => fake()->randomFloat(2, 40, 100),
                'audience_quality' => fake()->randomFloat(2, 40, 100),
                'content_relevance' => fake()->randomFloat(2, 40, 100),
                'reliability' => fake()->randomFloat(2, 40, 100),
                'cost_efficiency' => fake()->randomFloat(2, 40, 100),
            ],
            'computed_at' => now(),
        ];
    }
}
