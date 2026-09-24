<?php

namespace Database\Factories;

use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = fake()->randomElement([
            'instagram', 'tiktok', 'youtube', 'x', 'linkedin', 'shopify', 'ga4',
        ]);

        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'provider' => $provider,
            'name' => ucfirst($provider).' connector',
            'status' => IntegrationStatus::Connected->value,
            'credentials' => [
                'account_id' => 'acct_'.fake()->numerify('########'),
                'access_token' => fake()->sha1(),
            ],
            'auto_verify' => true,
            'last_synced_at' => now(),
            'last_error' => null,
        ];
    }

    public function degraded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IntegrationStatus::Degraded->value,
            'last_error' => 'Rate limited by provider API.',
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IntegrationStatus::Disconnected->value,
            'last_error' => 'Access token expired.',
        ]);
    }
}
