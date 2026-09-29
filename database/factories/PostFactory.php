<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'user_id' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'campaign_id' => null,
            'title' => fake()->sentence(4),
            'status' => PostStatus::Draft->value,
            'tags' => [fake()->word(), fake()->word()],
            'notes' => fake()->optional()->sentence(),
            'published_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Draft->value,
            'published_at' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Scheduled->value,
            'published_at' => null,
        ]);
    }

    public function publishing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Publishing->value,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Published->value,
            'published_at' => now()->subHour(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Failed->value,
            'published_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Cancelled->value,
            'published_at' => null,
        ]);
    }

    public function forCampaign(?int $campaignId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'campaign_id' => $campaignId ?? \App\Models\Campaign::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->id,
        ]);
    }
}
