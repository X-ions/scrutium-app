<?php

namespace Database\Factories;

use App\Models\SocialHubNotification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialHubNotification>
 */
class SocialHubNotificationFactory extends Factory
{
    protected $model = SocialHubNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'user_id' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'type' => fake()->randomElement([
                'post.published',
                'post.failed',
                'token.expired',
                'comment.new',
                'analytics.ready',
            ]),
            'title' => fake()->sentence(4),
            'message' => fake()->sentence(12),
            'data' => ['post_id' => fake()->numerify('####')],
            'action_url' => '/posts/'.fake()->numerify('####'),
            'is_read' => false,
            'read_at' => null,
            'priority' => 'normal',
            'expires_at' => null,
        ];
    }

    public function broadcast(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function unread(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'critical',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
