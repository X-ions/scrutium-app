<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Resolves the tenant that the current request belongs to.
 *
 * Resolution order:
 *  1. An explicitly bound tenant (e.g. resolved by middleware or a test).
 *  2. The authenticated user's tenant.
 *
 * When nothing is resolved the value is null, which callers treat as
 * "unscoped" — this is what keeps console commands, seeders and migrations
 * (all of which run without a user) working.
 */
class TenantContext
{
    protected static ?Tenant $tenant = null;

    /**
     * Whether the tenant was pinned explicitly for this process/request.
     * The auth-derived path is intentionally never cached, so switching
     * users mid-process (tests, queue jobs) always re-resolves correctly.
     */
    protected static bool $pinned = false;

    public static function set(?Tenant $tenant): void
    {
        static::$tenant = $tenant;
        static::$pinned = true;

        if ($tenant) {
            app()->instance('tenant', $tenant);
        } else {
            app()->forgetInstance('tenant');
        }
    }

    public static function tenant(): ?Tenant
    {
        if (static::$pinned) {
            return static::$tenant;
        }

        if (app()->bound('tenant')) {
            return app('tenant');
        }

        $user = auth()->hasUser() ? auth()->user() : null;

        return $user?->tenant;
    }

    public static function id(): ?int
    {
        return static::tenant()?->id;
    }

    public static function forget(): void
    {
        static::$tenant = null;
        static::$pinned = false;

        if (app()->bound('tenant')) {
            app()->forgetInstance('tenant');
        }
    }
}
