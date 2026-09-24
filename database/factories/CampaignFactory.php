<?php

namespace Database\Factories;

use App\Enums\CampaignStage;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->catchPhrase();

        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'owner_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'objective' => fake()->randomElement([
                'Awareness', 'Consideration', 'Conversion', 'Retention', 'Product launch',
            ]),
            'brief' => fake()->paragraph(),
            'stage' => fake()->randomElement(CampaignStage::cases())->value,
            'status' => CampaignStatus::Draft->value,
            'budget_total' => fake()->numberBetween(5_000, 250_000),
            'budget_spent' => fn (array $attributes) => round(
                (float) $attributes['budget_total'] * fake()->randomFloat(2, 0, 0.85),
                2,
            ),
            'currency' => 'USD',
            'starts_at' => fake()->dateTimeBetween('-3 months', 'now'),
            'ends_at' => fake()->dateTimeBetween('now', '+3 months'),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::Active->value,
            'stage' => CampaignStage::Live->value,
        ]);
    }

    public function inStage(CampaignStage $stage): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => $stage->value,
        ]);
    }
}
