<?php

declare(strict_types=1);

namespace App\Http\Middleware\SocialHub;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit for the unauthenticated webhook receiver and any other
 * provider-facing endpoint.
 *
 * Webhook endpoints cannot authenticate a caller, so the only defence against a
 * flood is a bound on requests per source. The key is deliberately per-provider
 * and per-source-address: one misbehaving integration cannot exhaust the budget
 * of another, and a provider that retries aggressively is still bounded.
 *
 * The limit is applied in addition to (and after) signature verification, so an
 * unsigned flood is rejected by the signature check without ever consuming a
 * token from a legitimate sender's bucket when registered in that order — hence
 * the note in the route registration: put `VerifyProviderWebhook` first.
 */
class ThrottleProviderApi
{
    /**
     * Default: 120 requests per minute per source per provider.
     */
    public const DEFAULT_LIMIT = 120;

    public const DEFAULT_WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next, ?string $limit = null, ?string $window = null): Response
    {
        $provider = (string) ($request->route('provider') ?: 'global');
        $maxAttempts = (int) ($limit ?? self::DEFAULT_LIMIT);
        $decay = max(1, (int) ($window ?? self::DEFAULT_WINDOW_SECONDS));
        $source = $this->sourceKey($request);
        $key = sprintf('socialhub:throttle:%s:%s', $provider, $source);

        $current = (int) Cache::get($key, 0);

        if ($current >= $maxAttempts) {
            Log::notice('SocialHub provider endpoint throttled.', [
                'provider' => $provider,
                'source' => $source,
                'attempts' => $current,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'rate_limited',
                'message' => 'Too many requests. Retry after the interval.',
            ], 429, [
                'Retry-After' => (string) $decay,
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        Cache::put($key, $current + 1, $decay);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $maxAttempts - $current - 1));

        return $response;
    }

    /**
     * Trust a proxy's forwarded address only when a proxy is actually trusted.
     *
     * `$request->ip()` cannot be used to answer that question: this app calls
     * `trustProxies(at: '**')` in bootstrap so the edge's `X-Forwarded-Proto` is
     * honoured, and that call sets Symfony's *global* trusted-proxy list. Once
     * it is set, `ip()` always resolves the client from `X-Forwarded-For`, and
     * reading `config('app.trusted_proxies')` tells us nothing because this
     * project has no `config/app.php` key to set. Honouring the header by
     * default would therefore let any caller pick its own bucket by rotating
     * `X-Forwarded-For`, which is exactly what a rate limit must prevent.
     *
     * So the forwarded address is opt-in, via `socialhub.webhooks.trust_forwarded_for`
     * or an explicit `app.trusted_proxies` list, and the address is taken from
     * the transport when it is not. The safe default costs a shared bucket behind
     * a proxy that rewrites the remote address, which is a capacity decision for
     * the deployer rather than a security hole.
     */
    private function sourceKey(Request $request): string
    {
        if ($this->trustsForwardedFor()) {
            $forwarded = $request->headers->get('X-Forwarded-For');

            if (is_string($forwarded) && trim($forwarded) !== '') {
                $first = trim(explode(',', $forwarded)[0]);

                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return hash('sha256', $first);
                }
            }
        }

        return hash('sha256', $this->transportAddress($request));
    }

    private function trustsForwardedFor(): bool
    {
        $configured = config('app.trusted_proxies');

        if (is_array($configured) && $configured !== []) {
            return true;
        }

        return (bool) config('socialhub.webhooks.trust_forwarded_for', false);
    }

    private function transportAddress(Request $request): string
    {
        $remote = $request->server->get('REMOTE_ADDR');

        if (is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) !== false) {
            return $remote;
        }

        return $request->ip() ?? 'unknown';
    }
}
