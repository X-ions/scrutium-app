<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'generated_by' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => fake()->words(3, true).' report',
            'type' => fake()->randomElement(['performance', 'deliverables', 'integrity', 'roi']),
            'status' => ReportStatus::Draft->value,
            'version' => 1,
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'frozen_at' => null,
            'payload' => [
                'roi' => fake()->randomFloat(2, 0.5, 6.5),
                'emv' => fake()->numberBetween(5_000, 250_000),
                'cpe' => fake()->randomFloat(2, 0.05, 1.5),
            ],
            'file_path' => null,
        ];
    }

    public function frozen(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Frozen->value,
            'frozen_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Published->value,
            'frozen_at' => now(),
        ]);
    }
}
