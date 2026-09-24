<?php

namespace Database\Factories;

use App\Models\AlertSubscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertSubscription>
 */
class AlertSubscriptionFactory extends Factory
{
    protected $model = AlertSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'user_id' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'type' => null,
            'channel' => 'in_app',
            'is_active' => true,
        ];
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => 'email',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
