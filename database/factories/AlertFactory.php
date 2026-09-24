<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'assigned_to' => null,
            'type' => fake()->randomElement([
                'missing_disclosure', 'late_deliverable', 'sync_failure',
                'provenance_drop', 'budget_overrun',
            ]),
            'severity' => fake()->randomElement(AlertSeverity::cases())->value,
            'status' => AlertStatus::Open->value,
            'title' => fake()->sentence(5),
            'message' => fake()->paragraph(),
            'subject_type' => null,
            'subject_id' => null,
            'resolved_at' => null,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => AlertSeverity::Critical->value,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AlertStatus::Resolved->value,
            'resolved_at' => now(),
        ]);
    }
}
