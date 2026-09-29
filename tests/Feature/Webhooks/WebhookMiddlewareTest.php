<?php

declare(strict_types=1);

use App\Http\Middleware\SocialHub\SecurityHeaders;
use App\Http\Middleware\SocialHub\ThrottleProviderApi;
use App\Http\Middleware\SocialHub\VerifyProviderWebhook;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Support\Engagement\FakeEngagementProvider;

require_once __DIR__.'/../../Support/engagement_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::forget();
    useFakeEngagementProviders();
    Cache::clear();
});

afterEach(fn () => TenantContext::forget());

describe('VerifyProviderWebhook', function (): void {
    it('passes a correctly signed request through with a verified request on it', function (): void {
        $payload = ['event_id' => 'evt-1', 'object' => 'page'];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/webhooks/facebook',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, \Tests\Support\Engagement\FakeEngagementProvider::SECRET)],
            $raw,
        );

        $captured = null;
        $response = (new VerifyProviderWebhook)->handle($request, function (Request $request) use (&$captured) {
            $captured = $request->attributes->get(VerifyProviderWebhook::REQUEST_ATTRIBUTE);

            return response()->json(['ok' => true]);
        }, 'facebook');

        expect($response->getStatusCode())->toBe(200)
            ->and($captured)->toBeInstanceOf(VerifiedWebhookRequest::class)
            // The raw body must survive untouched: a re-serialised payload would
            // break the HMAC on the next verification.
            ->and($captured->rawBody)->toBe($raw)
            ->and($captured->provider)->toBe('facebook')
            ->and($captured->payload)->toBe($payload);
    });

    it('rejects a request with a bad signature before the handler runs', function (): void {
        $raw = json_encode(['event_id' => 'evt-1'], JSON_THROW_ON_ERROR);

        $request = Request::create('/webhooks/facebook', 'POST', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.str_repeat('a', 64),
        ], $raw);

        $handlerRan = false;

        $response = (new VerifyProviderWebhook)->handle($request, function () use (&$handlerRan) {
            $handlerRan = true;

            return response()->json(['ok' => true]);
        }, 'facebook');

        expect($handlerRan)->toBeFalse()
            ->and($response->getStatusCode())->toBe(401)
            ->and($response->getData(true)['error'])->toBe('invalid_signature')
            // The rejection must not leak what a valid signature looks like.
            ->and($response->getContent())->not->toContain(FakeEngagementProvider::SECRET);
    });

    it('rejects an empty body', function (): void {
        $request = Request::create('/webhooks/facebook', 'POST', [], [], [], [], '');

        $response = (new VerifyProviderWebhook)->handle($request, fn () => response()->json([]), 'facebook');

        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true)['error'])->toBe('empty_body');
    });

    it('rejects a body that is not json', function (): void {
        $request = Request::create('/webhooks/facebook', 'POST', [], [], [], [], 'not json');

        $response = (new VerifyProviderWebhook)->handle($request, fn () => response()->json([]), 'facebook');

        expect($response->getData(true)['error'])->toBe('invalid_json');
    });

    it('rejects a body over the size limit', function (): void {
        $raw = json_encode(['padding' => str_repeat('x', 2_000_000)], JSON_THROW_ON_ERROR);

        $request = Request::create('/webhooks/facebook', 'POST', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=x',
        ], $raw);

        $response = (new VerifyProviderWebhook)->handle($request, fn () => response()->json([]), 'facebook');

        expect($response->getStatusCode())->toBe(413)
            ->and($response->getData(true)['error'])->toBe('body_too_large');
    });

    it('rejects an unknown provider without consulting the registry', function (): void {
        $request = Request::create('/webhooks/myspace', 'POST', [], [], [], [], '{}');

        $response = (new VerifyProviderWebhook)->handle($request, fn () => response()->json([]));

        expect($response->getData(true)['error'])->toBe('unknown_provider');
    });
});

describe('ThrottleProviderApi', function (): void {
    /**
     * The middleware reads the provider from the route, so it is exercised
     * through real routes rather than a hand-built request.
     */
    function throttledRoutes(): void
    {
        Route::post('/throttle/{provider}', fn () => response()->json(['ok' => true]))
            ->middleware(ThrottleProviderApi::class.':2,60');
    }

    it('lets requests through up to the limit and then refuses', function (): void {
        throttledRoutes();

        $this->postJson('/throttle/facebook')->assertOk();
        $this->postJson('/throttle/facebook')->assertOk();

        $refused = $this->postJson('/throttle/facebook');

        $refused->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error', 'rate_limited');
    });

    it('keeps one providers budget separate from anothers', function (): void {
        throttledRoutes();

        $this->postJson('/throttle/facebook')->assertOk();
        $this->postJson('/throttle/facebook')->assertOk();

        $this->postJson('/throttle/facebook')->assertStatus(429);

        // A different integration has its own budget and is unaffected.
        $this->postJson('/throttle/instagram')->assertOk();
    });

    it('reports the remaining budget to the caller', function (): void {
        throttledRoutes();

        $this->postJson('/throttle/facebook')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '1');

        $this->postJson('/throttle/facebook')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '0');
    });

    it('ignores a forwarded address when no proxy is trusted', function (): void {
        config()->set('app.trusted_proxies', null);

        Route::post('/throttle/{provider}', fn () => response()->json(['ok' => true]))
            ->middleware(ThrottleProviderApi::class.':2,60');

        $headers = ['X-Forwarded-For' => '10.0.0.1'];

        $this->postJson('/throttle/facebook', [], $headers)->assertOk();
        $this->postJson('/throttle/facebook', [], $headers)->assertOk();

        // A caller must not be able to pick its own bucket with a header: the
        // key is the real remote address, which is unchanged here.
        $this->postJson('/throttle/facebook', [], $headers)->assertStatus(429);

        // A different real address still has its own budget.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->postJson('/throttle/facebook', [], $headers)
            ->assertOk();
    });

    it('honours the forwarded address once a proxy is explicitly trusted', function (): void {
        config()->set('socialhub.webhooks.trust_forwarded_for', true);

        Route::post('/throttle/{provider}', fn () => response()->json(['ok' => true]))
            ->middleware(ThrottleProviderApi::class.':2,60');

        $first = ['X-Forwarded-For' => '198.51.100.10'];
        $second = ['X-Forwarded-For' => '198.51.100.20'];

        $this->postJson('/throttle/facebook', [], $first)->assertOk();
        $this->postJson('/throttle/facebook', [], $first)->assertOk();
        $this->postJson('/throttle/facebook', [], $first)->assertStatus(429);

        // Behind a real proxy the forwarded address is the caller, so a
        // different one is a different caller with a separate budget.
        $this->postJson('/throttle/facebook', [], $second)->assertOk();
    });
});

describe('SecurityHeaders', function (): void {
    it('hardens a json response', function (): void {
        $response = (new SecurityHeaders)->handle(
            Request::create('/socialhub/media', 'GET'),
            fn () => response()->json(['ok' => true]),
        );

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('tightens the framework default cache policy to no-store', function (): void {
        $response = (new SecurityHeaders)->handle(
            Request::create('/socialhub/media', 'GET'),
            // Laravel's own default is `no-cache, private`.
            fn () => response()->json(['ok' => true]),
        );

        expect($response->headers->get('Cache-Control'))->toBe('no-store, private');
    });

    it('leaves a deliberately cacheable response alone', function (): void {
        $response = (new SecurityHeaders)->handle(
            Request::create('/socialhub/media/thumb', 'GET'),
            fn () => response()->json(['ok' => true], 200, [
                'Cache-Control' => 'public, max-age=86400',
            ]),
        );

        expect($response->headers->get('Cache-Control'))->toContain('public')
            ->toContain('max-age=86400');
    });
});
