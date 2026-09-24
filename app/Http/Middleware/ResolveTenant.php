<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and isolates the authenticated user's workspace for every request.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(! $user || ! $user->tenant, 403, 'A workspace is required.');

        TenantContext::forget();
        TenantContext::set($user->tenant);

        try {
            if (! $user->last_active_at || $user->last_active_at->lt(now()->subMinutes(5))) {
                $user->forceFill(['last_active_at' => now()])->saveQuietly();
            }

            return $next($request);
        } finally {
            TenantContext::forget();
        }
    }
}