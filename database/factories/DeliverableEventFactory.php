<?php

namespace Database\Factories;

use App\Enums\DeliverableStatus;
use App\Models\Deliverable;
use App\Models\DeliverableEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliverableEvent>
 */
class DeliverableEventFactory extends Factory
{
    protected $model = DeliverableEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deliverable_id' => fn () => Deliverable::factory()->create()->id,
            'user_id' => null,
            'action' => 'created',
            'from_status' => null,
            'to_status' => DeliverableStatus::Pending->value,
            'note' => null,
            'meta' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'submitted',
            'from_status' => DeliverableStatus::Pending->value,
            'to_status' => DeliverableStatus::Submitted->value,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'approved',
            'from_status' => DeliverableStatus::Submitted->value,
            'to_status' => DeliverableStatus::Approved->value,
        ]);
    }
}
