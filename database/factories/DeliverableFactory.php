<?php

namespace Database\Factories;

use App\Enums\DeliverableStatus;
use App\Enums\DeliverableType;
use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\Deliverable;
use App\Models\Influencer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deliverable>
 */
class DeliverableFactory extends Factory
{
    protected $model = Deliverable::class;

    /**
     * Parent models are created against the same tenant so the resulting
     * graph is always internally consistent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'campaign_id' => fn (array $attributes) => Campaign::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'influencer_id' => fn (array $attributes) => Influencer::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'verified_by' => null,
            'title' => fake()->sentence(4),
            'type' => fake()->randomElement(DeliverableType::cases())->value,
            'platform' => fake()->randomElement(Platform::cases())->value,
            'contracted_units' => fake()->numberBetween(1, 3),
            'delivered_units' => 0,
            'fee' => fake()->numberBetween(150, 12_000),
            'status' => DeliverableStatus::Pending->value,
            'due_at' => fake()->dateTimeBetween('-2 weeks', '+3 weeks'),
            'posted_at' => null,
            'verified_at' => null,
            'evidence_path' => null,
            'rejection_reason' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliverableStatus::Submitted->value,
            'posted_at' => now()->subDay(),
            'evidence_path' => 'deliverables/evidence/demo.jpg',
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliverableStatus::Approved->value,
            'posted_at' => now()->subWeek(),
            'verified_at' => now()->subDays(3),
            'evidence_path' => 'deliverables/evidence/demo.jpg',
            'delivered_units' => $attributes['contracted_units'],
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliverableStatus::Rejected->value,
            'posted_at' => now()->subWeek(),
            'rejection_reason' => 'Disclosure missing from caption.',
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliverableStatus::Late->value,
            'due_at' => now()->subDays(5),
        ]);
    }
}
