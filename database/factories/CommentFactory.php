<?php

namespace Database\Factories;

use App\Enums\SocialPlatform;
use App\Models\Comment;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'post_variant_id' => fn () => PostVariant::factory()->create()->id,
            'social_account_id' => fn () => SocialAccount::factory()->create()->id,
            'parent_comment_id' => null,
            'provider' => fake()->randomElement(SocialPlatform::cases()),
            'provider_comment_id' => fake()->unique()->numerify('cmt_##########'),
            'author_provider_id' => fake()->numerify('##########'),
            'author_username' => fake()->unique()->userName(),
            'author_display_name' => fake()->name(),
            'author_avatar_url' => fake()->imageUrl(120, 120),
            'content' => fake()->sentence(10),
            'like_count' => fake()->numberBetween(0, 500),
            'reply_count' => 0,
            'is_hidden' => false,
            'is_deleted' => false,
            'provider_created_at' => now()->subHours(fake()->numberBetween(1, 72)),
            'synced_at' => now(),
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_hidden' => true,
        ]);
    }

    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_deleted' => true,
        ]);
    }

    public function replyTo(Comment $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $parent->tenant_id,
            'post_variant_id' => $parent->post_variant_id,
            'social_account_id' => $parent->social_account_id,
            'provider' => $parent->provider,
            'parent_comment_id' => $parent->getKey(),
        ]);
    }

    public function unsynced(): static
    {
        return $this->state(fn (array $attributes) => [
            'synced_at' => null,
        ]);
    }
}
