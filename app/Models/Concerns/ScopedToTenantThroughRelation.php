<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the tenant scope to models that have no `tenant_id` column of their own.
 *
 * These tables reach the tenant through a parent relation, so the scope filters on
 * the dotted relation path declared by {@see tenantScopedRelation()} instead of a
 * local column. This keeps the same "unscoped when no tenant is resolved" behaviour
 * as {@see BelongsToTenant}.
 */
trait ScopedToTenantThroughRelation
{
    public static function bootScopedToTenantThroughRelation(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = TenantContext::id();

            if ($tenantId === null) {
                return;
            }

            $builder->whereHas(
                static::tenantScopedRelation(),
                fn (Builder $query) => $query->where('tenant_id', $tenantId)
            );
        });
    }

    /**
     * Dotted relation path from this model to a `tenant_id`-bearing model.
     */
    abstract protected static function tenantScopedRelation(): string;
}
