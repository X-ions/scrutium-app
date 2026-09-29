<?php

declare(strict_types=1);

namespace App\Http\Middleware\SocialHub;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response hardening for every SocialHub route.
 *
 * Distinct from `App\Http\Middleware\SecurityHeaders`, which sets a per-request
 * CSP nonce for the dashboard's Blade views. This one is for the JSON surface —
 * the media library endpoints and the provider webhook receiver — where the
 * correct policy is stricter, not looser: no framing, no sniffing, no referrer
 * leakage to a third-party CDN, and no cache of a response that may contain
 * workspace data.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // Symfony always populates a Cache-Control header — Laravel's JSON
        // responses default to `no-cache, private` — so `has()` can never be
        // used to detect "unset". The rule that does work: a response only
        // keeps its own policy when it was deliberately made cacheable
        // (`public`). Everything else is tightened to no-store, so a workspace's
        // media listing or inbox payload cannot be replayed out of a shared
        // cache or read from a browser history entry.
        $cacheControl = (string) $response->headers->get('Cache-Control');

        if (! str_contains(strtolower($cacheControl), 'public')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
