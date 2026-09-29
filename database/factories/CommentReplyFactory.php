<?php

namespace Database\Factories;

use App\Enums\CommentSyncStatus;
use App\Enums\SocialPlatform;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommentReply>
 */
class CommentReplyFactory extends Factory
{
    protected $model = CommentReply::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'comment_id' => fn () => Comment::factory()->create()->id,
            'user_id' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => self::tenantIdFor($attributes['comment_id'])])->id,
            'social_account_id' => fn (array $attributes) => SocialAccount::factory()
                ->create(['tenant_id' => self::tenantIdFor($attributes['comment_id'])])->id,
            'provider' => fake()->randomElement(SocialPlatform::cases()),
            'content' => fake()->sentence(8),
            'provider_reply_id' => null,
            'status' => CommentSyncStatus::Pending->value,
            'error_message' => null,
            'sent_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommentSyncStatus::Sent->value,
            'provider_reply_id' => fake()->numerify('reply_##########'),
            'sent_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Provider rejected the reply.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommentSyncStatus::Failed->value,
            'error_message' => $reason,
        ]);
    }

    private static function tenantIdFor(int $commentId): int
    {
        return (int) Comment::withoutGlobalScopes()->findOrFail($commentId)->tenant_id;
    }
}
