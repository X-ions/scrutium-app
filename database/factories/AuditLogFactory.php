<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'user_id' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'event' => fake()->randomElement([
                'social_account.connected',
                'social_account.disconnected',
                'post.created',
                'post.published',
            ]),
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function forModel(object $model, ?string $event = null): static
    {
        return $this->state(fn (array $attributes) => [
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'event' => $event ?? 'updated',
        ]);
    }

    public function forSocialAccount(SocialAccount $account, ?string $event = null): static
    {
        return $this->forModel($account, $event ?? 'social_account.updated');
    }

    public function withChanges(array $old, array $new): static
    {
        return $this->state(fn (array $attributes) => [
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
