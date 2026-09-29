<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccountToken>
 */
class SocialAccountTokenFactory extends Factory
{
    protected $model = SocialAccountToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_account_id' => fn () => SocialAccount::factory()->create()->id,
            'access_token' => 'access_'.fake()->sha256(),
            'refresh_token' => 'refresh_'.fake()->sha256(),
            'id_token' => null,
            'token_type' => 'Bearer',
            'expires_at' => now()->addDays(60),
            'scope' => 'read write publish',
            'metadata' => ['issued_by' => fake()->company()],
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addHours(6),
        ]);
    }

    public function withoutRefreshToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'refresh_token' => null,
        ]);
    }
}
