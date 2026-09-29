<?php

namespace Database\Factories;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = fake()->randomElement(SocialPlatform::cases());

        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'provider' => $provider,
            'provider_account_id' => fake()->unique()->numerify('##########'),
            'provider_username' => fake()->unique()->userName(),
            'provider_display_name' => fake()->company(),
            'provider_avatar_url' => fake()->imageUrl(200, 200),
            'account_type' => fake()->randomElement($provider->accountTypes()),
            'status' => SocialAccountStatus::Connected->value,
            'permissions' => ['read', 'publish'],
            'capabilities' => ['scheduling' => $provider->supportsNativeScheduling()],
            'is_default' => false,
            'last_synced_at' => now(),
            'last_error' => null,
            'metadata' => ['locale' => 'en'],
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialAccountStatus::Connected->value,
            'last_error' => null,
            'last_synced_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialAccountStatus::Expired->value,
            'last_error' => 'Access token expired.',
        ])->afterCreating(function (SocialAccount $account) {
            SocialAccountToken::factory()->expired()->create([
                'social_account_id' => $account->id,
            ]);
        });
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialAccountStatus::Revoked->value,
            'last_error' => 'Access revoked by the provider.',
        ]);
    }

    public function errored(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialAccountStatus::Error->value,
            'last_error' => 'Provider API returned an unexpected error.',
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialAccountStatus::Disconnected->value,
            'last_error' => null,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function forProvider(SocialPlatform $provider): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => $provider,
            'account_type' => fake()->randomElement($provider->accountTypes()),
        ]);
    }

    public function withToken(): static
    {
        return $this->afterCreating(function (SocialAccount $account) {
            SocialAccountToken::factory()->create(['social_account_id' => $account->id]);
        });
    }
}
