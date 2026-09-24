<?php

namespace Database\Factories;

use App\Models\ScoreConfig;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScoreConfig>
 */
class ScoreConfigFactory extends Factory
{
    protected $model = ScoreConfig::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Scrutium default v'.fake()->unique()->numberBetween(1, 999),
            'description' => 'Weighted pulse score used to rank roster candidates.',
            'weights' => ScoreConfig::DEFAULT_WEIGHTS,
            'cohort_rules' => [
                'min_followers' => 5_000,
                'platforms' => ['instagram', 'tiktok', 'youtube'],
            ],
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
            'name' => 'Scrutium default',
        ]);
    }
}
