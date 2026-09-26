<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generated before the response is rendered so the same nonce reaches the
        // CSP header and every nonce'd tag the Blade view emits.
        $nonce = base64_encode(random_bytes(16));

        View::share('cspNonce', $nonce);

        // Laravel's Vite instance adds the nonce to every <script> and <link> tag
        // it generates, so the @vite(...) output stays covered without hacking it.
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');

        // Every response from this app is per-user: it carries a CSRF token, a
        // flash message and session state. Without an explicit directive the
        // Vercel PHP runtime re-labels the page `public, max-age=0,
        // must-revalidate`, which makes it eligible for the shared edge cache —
        // and a cached response drops Set-Cookie, so the browser never receives
        // the session cookie and every POST (sign-in included) fails with a 419.
        // Being explicit keeps the page out of shared caches and is simply the
        // correct policy for an authenticated app.
        $response->headers->set('Cache-Control', 'no-store, no-cache, private, must-revalidate');
        $response->headers->set('Vary', 'Cookie');

        // 'unsafe-eval' stays in script-src because Alpine v3 evaluates x-data,
        // x-on:, :class and friends through new Function(). It must only be dropped
        // together with a switch to the @alpinejs/csp build plus full browser QA.
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self' https:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
