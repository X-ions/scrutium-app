<?php

namespace Database\Factories;

use App\Enums\PostVariantStatus;
use App\Enums\SocialPlatform;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostVariant>
 */
class PostVariantFactory extends Factory
{
    protected $model = PostVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => fn () => Post::factory()->create()->id,
            'social_account_id' => fn () => SocialAccount::factory()->create()->id,
            'provider' => fake()->randomElement(SocialPlatform::cases()),
            'caption' => fake()->sentence(12),
            'media_ids' => [],
            'hashtags' => [fake()->word()],
            'mentions' => [],
            'location_id' => null,
            'location_name' => null,
            'platform_specific' => [],
            'status' => PostVariantStatus::Pending->value,
            'provider_post_id' => null,
            'provider_post_url' => null,
            'error_message' => null,
            'retry_count' => 0,
            'scheduled_at' => null,
            'published_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostVariantStatus::Pending->value,
        ]);
    }

    public function scheduled(?\DateTimeInterface $when = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostVariantStatus::Scheduled->value,
            'scheduled_at' => $when ?? now()->addHour(),
        ]);
    }

    public function publishing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostVariantStatus::Publishing->value,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostVariantStatus::Published->value,
            'provider_post_id' => fake()->unique()->numerify('##########'),
            'provider_post_url' => fake()->url(),
            'published_at' => now()->subHour(),
        ]);
    }

    public function failed(string $reason = 'Provider rejected the payload.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostVariantStatus::Failed->value,
            'error_message' => $reason,
            'retry_count' => 1,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostVariantStatus::Cancelled->value,
        ]);
    }

    public function forProvider(SocialPlatform $provider): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => $provider,
        ]);
    }
}
