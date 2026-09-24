<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the tenant resolved by {@see TenantContext}.
 *
 * Models using this trait are automatically filtered on read and stamped
 * with the resolved tenant on create. When no tenant is resolved (console
 * commands, seeders) the scope is a no-op so data can still be built.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = TenantContext::id();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $tenantId);
            }
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', TenantContext::id());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
