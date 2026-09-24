<?php

namespace Database\Factories;

use App\Enums\InfluencerTier;
use App\Enums\Platform;
use App\Enums\VettingStatus;
use App\Models\Influencer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Influencer>
 */
class InfluencerFactory extends Factory
{
    protected $model = Influencer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'handle' => '@'.fake()->unique()->userName(),
            'platform' => fake()->randomElement(Platform::cases())->value,
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'country' => fake()->countryCode(),
            'avatar_url' => null,
            'followers' => fake()->numberBetween(2_000, 2_500_000),
            'engagement_rate' => fake()->randomFloat(2, 0.5, 9.5),
            'pulse_score' => fake()->randomFloat(2, 40, 98),
            'tier' => fn (array $attributes) => InfluencerTier::fromFollowers((int) $attributes['followers'])->value,
            'vetting_status' => VettingStatus::Sourced->value,
            'notes' => null,
            'last_synced_at' => now(),
        ];
    }

    public function vetted(): static
    {
        return $this->state(fn (array $attributes) => [
            'vetting_status' => VettingStatus::Vetted->value,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'vetting_status' => VettingStatus::Rejected->value,
        ]);
    }

    public function onPlatform(Platform $platform): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => $platform->value,
        ]);
    }

    public function withFollowers(int $followers): static
    {
        return $this->state(fn (array $attributes) => [
            'followers' => $followers,
            'tier' => InfluencerTier::fromFollowers($followers)->value,
        ]);
    }
}
