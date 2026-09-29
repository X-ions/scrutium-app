<?php

namespace Database\Factories;

use App\Enums\PublishingAttemptStatus;
use App\Models\PostVariant;
use App\Models\PublishingAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublishingAttempt>
 */
class PublishingAttemptFactory extends Factory
{
    protected $model = PublishingAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_variant_id' => fn () => PostVariant::factory()->create()->id,
            'scheduled_post_id' => null,
            'attempt_number' => 1,
            'status' => PublishingAttemptStatus::Pending->value,
            'request_payload' => ['caption' => fake()->sentence(6)],
            'response_payload' => null,
            'error_code' => null,
            'error_message' => null,
            'rate_limit_reset_at' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PublishingAttemptStatus::Processing->value,
        ]);
    }

    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PublishingAttemptStatus::Success->value,
            'response_payload' => ['id' => fake()->numerify('##########')],
            'completed_at' => now()->addSeconds(2),
        ]);
    }

    public function failed(string $reason = 'Provider returned 500.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PublishingAttemptStatus::Failed->value,
            'error_code' => 'server_error',
            'error_message' => $reason,
            'completed_at' => now()->addSeconds(2),
        ]);
    }

    public function rateLimited(?\DateTimeInterface $resetAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PublishingAttemptStatus::RateLimited->value,
            'error_code' => 'rate_limit_exceeded',
            'error_message' => 'Too many requests.',
            'rate_limit_reset_at' => $resetAt ?? now()->addMinutes(15),
            'completed_at' => now(),
        ]);
    }

    public function attempt(int $number): static
    {
        return $this->state(fn (array $attributes) => [
            'attempt_number' => $number,
        ]);
    }
}
