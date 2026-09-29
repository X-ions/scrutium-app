<?php

namespace Database\Factories;

use App\Enums\SocialPlatform;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'provider' => fake()->randomElement(SocialPlatform::cases()),
            'event_id' => fake()->unique()->numerify('evt_##########'),
            'event_type' => fake()->randomElement(['post.updated', 'comment.created', 'page.followed']),
            'payload' => ['object' => ['id' => fake()->numerify('####')]],
            'processed' => false,
            'processed_at' => null,
            'error_message' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processed' => true,
            'processed_at' => now(),
        ]);
    }

    public function unprocessed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processed' => false,
            'processed_at' => null,
        ]);
    }

    public function failed(string $reason = 'Handler threw an exception.'): static
    {
        return $this->state(fn (array $attributes) => [
            'error_message' => $reason,
        ]);
    }
}
