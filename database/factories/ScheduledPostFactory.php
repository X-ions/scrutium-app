<?php

namespace Database\Factories;

use App\Enums\ScheduledPostStatus;
use App\Models\PostVariant;
use App\Models\ScheduledPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledPost>
 */
class ScheduledPostFactory extends Factory
{
    protected $model = ScheduledPost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_variant_id' => fn () => PostVariant::factory()->create()->id,
            'scheduled_at' => now()->addHour(),
            'timezone' => 'UTC',
            'status' => ScheduledPostStatus::Pending->value,
            'job_id' => null,
            'attempts' => 0,
            'max_attempts' => 3,
            'last_error' => null,
            'processed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduledPostStatus::Pending->value,
        ]);
    }

    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduledPostStatus::Queued->value,
            'job_id' => fake()->uuid(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduledPostStatus::Processing->value,
            'attempts' => 1,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduledPostStatus::Published->value,
            'processed_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Provider rejected the request.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduledPostStatus::Failed->value,
            'last_error' => $reason,
            'attempts' => 3,
            'processed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduledPostStatus::Cancelled->value,
        ]);
    }

    public function at(\DateTimeInterface $when, string $timezone = 'UTC'): static
    {
        return $this->state(fn (array $attributes) => [
            'scheduled_at' => $when,
            'timezone' => $timezone,
        ]);
    }
}
