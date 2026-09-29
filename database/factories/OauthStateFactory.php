<?php

namespace Database\Factories;

use App\Enums\SocialPlatform;
use App\Models\OauthState;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OauthState>
 */
class OauthStateFactory extends Factory
{
    protected $model = OauthState::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'provider' => fake()->randomElement(SocialPlatform::cases()),
            'state' => Str::random(64),
            'code_verifier' => Str::random(128),
            'redirect_url' => url('/oauth/callback'),
            'scopes' => 'read write publish',
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function forProvider(SocialPlatform $provider): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => $provider,
        ]);
    }

    public function withScopes(string ...$scopes): static
    {
        return $this->state(fn (array $attributes) => [
            'scopes' => implode(' ', $scopes),
        ]);
    }
}
