<?php

declare(strict_types=1);

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Exceptions\Social\WebhookSignatureException;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\Tenant;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Data\AnalyticsQuery;
use App\Services\Social\Data\PublishPayload;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\ProviderHttpClient;
use App\Services\Social\ProviderRateLimiter;
use App\Services\Social\Providers\FacebookProvider;
use App\Services\Social\Support\AccountTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Every outbound call is faked, so this suite asserts the exact Graph API
 * endpoints the provider targets without ever touching the network.
 */
beforeEach(function () {
    config()->set('socialhub.providers.facebook.credentials', [
        'client_id' => 'test-app-id',
        'client_secret' => 'test-app-secret',
    ]);

    config()->set('socialhub.providers.facebook.oauth.client_id', 'test-app-id');
    config()->set('socialhub.providers.facebook.oauth.client_secret', 'test-app-secret');
    config()->set('socialhub.providers.facebook.oauth.webhook_secret', 'webhook-secret');
});

function facebookProvider(): FacebookProvider
{
    return new FacebookProvider(
        http: new ProviderHttpClient(
            limiter: new ProviderRateLimiter(redis: null),
            provider: 'facebook',
        ),
    );
}

/**
 * A connected Page with a stored page access token.
 */
function connectedPage(string $pageId = '1234567890', string $pageToken = 'page-access-token'): SocialAccount
{
    $tenant = Tenant::first() ?? Tenant::create(['name' => 'SocialHub Test', 'slug' => 'socialhub-test-'.uniqid()]);

    $account = new SocialAccount([
        'tenant_id' => $tenant->id,
        'provider' => 'facebook',
        'provider_account_id' => $pageId,
        'provider_display_name' => 'Test Page',
        'account_type' => 'page',
        'status' => 'connected',
        'metadata' => ['page_access_token' => $pageToken, 'granted_scopes' => ['pages_manage_posts']],
    ]);

    $account->save();

    $token = new SocialAccountToken([
        'social_account_id' => $account->id,
        'access_token' => $pageToken,
        'refresh_token' => 'user-long-lived-token',
        'token_type' => 'Bearer',
    ]);

    $token->save();

    $account->setRelation('token', $token);

    return $account;
}

it('reads the account profile from the graph me edge', function () {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response([
            'id' => '1234567890',
            'name' => 'Test Page',
            'username' => 'testpage',
            'followers_count' => 4242,
            'link' => 'https://facebook.com/testpage',
            'category' => 'Software',
            'picture' => ['data' => ['url' => 'https://scontent.test/pic.jpg']],
        ]),
    ]);

    $profile = facebookProvider()->getAccount(connectedPage());

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'graph.facebook.com/v23.0/me')
            && str_contains($request->url(), 'fields=')
            && $request->hasHeader('Authorization', 'Bearer page-access-token');
    });

    expect($profile->provider)->toBe('facebook')
        ->and($profile->providerAccountId)->toBe('1234567890')
        ->and($profile->displayName)->toBe('Test Page')
        ->and($profile->username)->toBe('testpage')
        ->and($profile->followerCount)->toBe(4242)
        ->and($profile->accountType)->toBe('page');
});

it('never puts the access token in the request URL', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1', 'name' => 'Page'])]);

    facebookProvider()->getAccount(connectedPage());

    Http::assertSent(function ($request) {
        return ! str_contains($request->url(), 'access_token=')
            && ! str_contains($request->url(), 'page-access-token');
    });
});

it('publishes a text post to the page feed edge', function () {
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['id' => '9001'], 200),
        'graph.facebook.com/*' => Http::response(['id' => '9001', 'message' => 'Hello world']),
    ]);

    $post = facebookProvider()->createPost(connectedPage(), [
        'payload' => new PublishPayload(text: 'Hello world'),
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/1234567890/feed')
            && $request->method() === 'POST'
            && $request['message'] === 'Hello world';
    });

    expect($post->providerPostId)->toBe('9001')
        ->and($post->contentType)->toBe(PublishPayload::TYPE_TEXT)
        ->and($post->permalink)->toContain('1234567890/posts/9001');
});

it('publishes an image post with an attached media handle', function () {
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['id' => '9002'], 200),
        'graph.facebook.com/*' => Http::response(['id' => '9002']),
    ]);

    $post = facebookProvider()->createPost(connectedPage(), [
        'payload' => new PublishPayload(
            text: 'Look at this',
            mediaIds: ['media-fbid-1'],
            contentType: PublishPayload::TYPE_IMAGE,
        ),
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/feed')
            && str_contains($request['attached_media'], 'media-fbid-1');
    });

    expect($post->providerPostId)->toBe('9002')
        ->and($post->contentType)->toBe(PublishPayload::TYPE_IMAGE);
});

it('publishes a link post with both message and link', function () {
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['id' => '9003'], 200),
        'graph.facebook.com/*' => Http::response(['id' => '9003']),
    ]);

    facebookProvider()->createPost(connectedPage(), [
        'payload' => new PublishPayload(
            text: 'Read this',
            link: 'https://example.com/article',
        ),
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/feed')
            && $request['link'] === 'https://example.com/article'
            && $request['message'] === 'Read this';
    });
});

it('publishes a carousel by creating each child then the parent', function () {
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['id' => 'child-id'], 200),
        'graph.facebook.com/*' => Http::response(['id' => 'parent-id']),
    ]);

    $post = facebookProvider()->createPost(connectedPage(), [
        'payload' => new PublishPayload(
            text: 'Carousel',
            mediaIds: ['a', 'b'],
        ),
    ]);

    expect($post->contentType)->toBe(PublishPayload::TYPE_CAROUSEL)
        ->and($post->providerPostId)->not->toBeEmpty();

    // Two children plus the parent that carries the attached_media handle.
    Http::assertSentCount(3);
});

it('rejects a scheduled post because Facebook has no native scheduling', function () {
    Http::fake();

    expect(fn () => facebookProvider()->createPost(connectedPage(), [
        'payload' => new PublishPayload(
            text: 'Later',
            scheduledAt: new DateTimeImmutable('+1 day'),
        ),
    ]))->toThrow(UnsupportedCapabilityException::class);

    Http::assertNothingSent();
});

it('rejects an unsupported content type without contacting the API', function () {
    config()->set('socialhub.providers.facebook.capabilities_override', ['carouselPublishing' => false]);

    Http::fake();

    expect(fn () => facebookProvider()->createPost(connectedPage(), [
        'payload' => new PublishPayload(text: 'Carousel', mediaIds: ['a', 'b']),
    ]))->toThrow(
        UnsupportedCapabilityException::class,
        'Provider "facebook" does not support the "carouselPublishing" capability.',
    );

    Http::assertNothingSent();
});

it('carries a user-facing message naming the network on a capability failure', function () {
    config()->set('socialhub.providers.facebook.capabilities_override', ['firstComment' => false]);

    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['id' => '9004'], 200),
        'graph.facebook.com/*' => Http::response(['id' => '9004']),
    ]);

    try {
        facebookProvider()->createPost(connectedPage(), [
            'payload' => new PublishPayload(text: 'Hi', firstComment: 'First!'),
        ]);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->userFacingError->userMessage)->toContain('Facebook')
            ->and($e->userFacingError->code)->toBe('unsupported_capability');
    }
});

it('maps a 429 to a RateLimitException carrying the Retry-After delay', function () {
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(
            ['error' => ['code' => 4, 'message' => 'Application request limit reached']],
            429,
            ['Retry-After' => '240'],
        ),
    ]);

    try {
        facebookProvider()->createPost(connectedPage(), [
            'payload' => new PublishPayload(text: 'Too fast'),
        ]);
        $this->fail('Expected a RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(240)
            ->and($e->provider)->toBe('facebook')
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

it('falls back to a non-zero delay when a 429 carries no Retry-After', function () {
    config()->set('socialhub.rate_limits.facebook.fallback_retry_after', 90);

    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['error' => ['code' => 17]], 429),
    ]);

    try {
        facebookProvider()->createPost(connectedPage(), [
            'payload' => new PublishPayload(text: 'Too fast'),
        ]);
        $this->fail('Expected a RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(90);
    }
});

it('maps a Graph permission error to a reconnect remediation', function () {
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response([
            'error' => ['code' => 200, 'message' => 'Invalid parameter'],
        ], 403),
    ]);

    try {
        facebookProvider()->createPost(connectedPage(), [
            'payload' => new PublishPayload(text: 'Denied'),
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('permission_missing')
            ->and($e->userFacingError->remediation)->toContain('Reconnect')
            ->and($e->status)->toBe(403)
            ->and($e->providerCode)->toBe('200');
    }
});

it('maps a Graph invalid-token error to a reconnectable user message', function () {
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response([
            'error' => ['code' => 190, 'message' => 'Invalid OAuth access token.'],
        ], 401),
    ]);

    try {
        facebookProvider()->createPost(connectedPage(), [
            'payload' => new PublishPayload(text: 'Expired'),
        ]);
        $this->fail('Expected an authentication exception.');
    } catch (\App\Exceptions\Social\TokenRevokedException $e) {
        expect($e->userFacingError->code)->toBe('token_revoked')
            ->and($e->userFacingError->remediation)->toContain('Reconnect');
    }
});

it('does not leak the access token into the exception message or context', function () {
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response([
            'error' => ['code' => 100, 'message' => 'Invalid parameter'],
        ], 400),
    ]);

    try {
        facebookProvider()->createPost(connectedPage(), [
            'payload' => new PublishPayload(text: 'Bad'),
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        $serialised = json_encode([
            'message' => $e->getMessage(),
            'context' => $e->context,
            'error' => $e->userFacingError?->toArray(),
        ]);

        expect($serialised)->not->toContain('page-access-token')
            ->and($serialised)->not->toContain('test-app-secret');
    }
});

it('refuses to call the API when credentials are missing', function () {
    config()->set('socialhub.providers.facebook.credentials', [
        'client_id' => '',
        'client_secret' => '',
    ]);

    Http::fake();

    expect(fn () => facebookProvider()->getAccount(connectedPage()))
        ->toThrow(ProviderNotConfiguredException::class);

    Http::assertNothingSent();
});

it('lists pages and follows Meta cursor pagination', function () {
    Http::fakeSequence()
        ->push([
            'data' => [['id' => 'p1', 'name' => 'Page One', 'access_token' => 'secret-page-token']],
            'paging' => ['cursors' => ['after' => 'CURSOR_1']],
        ])
        ->push([
            'data' => [['id' => 'p2', 'name' => 'Page Two']],
            'paging' => ['cursors' => ['after' => 'CURSOR_1']],
        ]);

    $pages = facebookProvider()->getPages(connectedPage());

    expect($pages)->toHaveCount(2)
        ->and($pages[0]->providerAccountId)->toBe('p1')
        ->and($pages[0]->displayName)->toBe('Page One')
        ->and($pages[1]->providerAccountId)->toBe('p2');

    Http::assertSentCount(2);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/me/accounts'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'after=CURSOR_1'));
});

it('does not expose the per-page access token in the returned profile', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'data' => [['id' => 'p1', 'name' => 'Page One', 'access_token' => 'secret-page-token']],
        ]),
    ]);

    $pages = facebookProvider()->getPages(connectedPage());

    expect($pages[0]->raw)->not->toHaveKey('access_token')
        ->and(json_encode($pages[0]->toArray()))->not->toContain('secret-page-token');
});

it('reads comments from the post comments edge', function () {
    Http::fake([
        'graph.facebook.com/*/1234567890/comments*' => Http::response([
            'data' => [[
                'id' => 'c1',
                'message' => 'Great post!',
                'created_time' => '2026-09-20T10:00:00+0000',
                'like_count' => 5,
                'from' => ['id' => 'u1', 'name' => 'Ada', 'username' => 'ada'],
            ]],
        ]),
    ]);

    $comments = facebookProvider()->getComments(connectedPage(), '1234567890');

    expect($comments)->toHaveCount(1)
        ->and($comments[0]->providerCommentId)->toBe('c1')
        ->and($comments[0]->content)->toBe('Great post!')
        ->and($comments[0]->authorUsername)->toBe('ada')
        ->and($comments[0]->likeCount)->toBe(5);
});

it('replies to a comment on the comment comments edge', function () {
    Http::fake(['graph.facebook.com/*/comments' => Http::response(['id' => 'reply-1'], 200)]);

    $comment = new \App\Services\Social\Data\ProviderComment(
        provider: 'facebook',
        providerCommentId: 'c1',
        providerPostId: '9001',
        content: 'Great post!',
    );

    facebookProvider()->replyToComment(connectedPage(), $comment, 'Thanks!');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/c1/comments')
            && $request['message'] === 'Thanks!';
    });
});

it('reads page insights and normalises the metric vocabulary', function () {
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response([
            'data' => [[
                'name' => 'post_impressions',
                'values' => [['value' => 1200, 'end_time' => '2026-09-28T00:00:00+0000']],
            ]],
        ]),
    ]);

    $batch = facebookProvider()->getAnalytics(
        connectedPage(),
        AnalyticsQuery::forWindow(7),
    );

    Http::assertSent(fn ($request) => str_contains($request->url(), '/1234567890/insights')
        && str_contains($request->url(), 'since=')
        && str_contains($request->url(), 'until='));

    expect($batch->provider)->toBe('facebook')
        ->and($batch->totalFor('impressions'))->toBe(1200.0)
        ->and($batch->availableMetrics())->toContain('impressions');
});

it('omits a metric the page did not report rather than reporting zero', function () {
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response([
            'data' => [[
                'name' => 'post_impressions',
                'values' => [['value' => 1200]],
            ]],
        ]),
    ]);

    $batch = facebookProvider()->getAnalytics(connectedPage(), AnalyticsQuery::forWindow(7));

    expect($batch->totalFor('impressions'))->toBe(1200.0)
        ->and($batch->totalFor('reach'))->toBeNull()
        ->and($batch->totalFor('views'))->toBeNull();
});

it('reads follower totals from the page edge', function () {
    Http::fake([
        'graph.facebook.com/*/1234567890*' => Http::response([
            'followers_count' => 815,
            'fan_count' => 1200,
        ]),
    ]);

    $stats = facebookProvider()->getFollowers(connectedPage());

    expect($stats->total)->toBe(815)
        ->and($stats->breakdown)->toBe(['fans' => 1200, 'followers' => 815]);
});

it('deletes a post on the post edge', function () {
    Http::fake(['graph.facebook.com/*/9001' => Http::response(['success' => true])]);

    expect(facebookProvider()->deletePost(connectedPage(), '9001'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/9001'));
});

it('rejects deletePost when the capability is disabled', function () {
    config()->set('socialhub.providers.facebook.capabilities_override', ['deletePost' => false]);

    Http::fake();

    expect(fn () => facebookProvider()->deletePost(connectedPage(), '9001'))
        ->toThrow(UnsupportedCapabilityException::class);

    Http::assertNothingSent();
});

it('deauthorizes on disconnect without throwing on failure', function () {
    Http::fake(['graph.facebook.com/*/me/permissions' => Http::response(['error' => ['code' => 100]], 400)]);

    facebookProvider()->disconnect(connectedPage());

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/me/permissions'));
});

it('reports the verified Facebook capability set', function () {
    $capabilities = facebookProvider()->getSupportedFeatures();

    expect($capabilities->publishing)->toBeTrue()
        ->and($capabilities->scheduling)->toBeFalse()
        ->and($capabilities->reels)->toBeFalse()
        ->and($capabilities->toArray())->toBe(
            PlatformCapabilities::facebook()->toArray(),
        );
});

it('starts the OAuth flow with state and a PKCE challenge', function () {
    Http::fake();

    $session = facebookProvider()->authenticate(new \App\Services\Social\Data\AuthRequest(
        provider: 'facebook',
        state: bin2hex(random_bytes(32)),
        codeVerifier: 'verifier-value-that-is-long-enough-for-pkce',
        redirectUri: 'https://app.test/social/facebook/callback',
    ));

    expect($session->authorizationUrl)->toStartWith('https://www.facebook.com/v23.0/dialog/oauth')
        ->and($session->authorizationUrl)->toContain('code_challenge=')
        ->and($session->authorizationUrl)->toContain('code_challenge_method=S256')
        ->and($session->authorizationUrl)->toContain('pages_manage_posts')
        ->and($session->authorizationUrl)->toContain('response_type=code')
        ->and($session->authorizationUrl)->not->toContain('client_secret');
});

it('exchanges the code server-side and never puts the client secret in the URL', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-token',
            'token_type' => 'bearer',
            'expires_in' => 5183,
        ]),
    ]);

    $session = facebookProvider()->authenticate(new \App\Services\Social\Data\AuthRequest(
        provider: 'facebook',
        code: 'auth-code',
        state: 'state-value',
        redirectUri: 'https://app.test/social/facebook/callback',
    ));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'oauth/access_token')
            && ! str_contains($request->url(), 'client_secret')
            && ! str_contains($request->url(), 'test-app-secret')
            && $request['client_secret'] === 'test-app-secret'
            && $request['code'] === 'auth-code';
    });

    expect($session->isCompleted())->toBeTrue()
        ->and($session->tokens->accessToken)->toBe('short-lived-token')
        ->and($session->tokens->expiresIn)->toBe(5183)
        ->and($session->tokens->isExpired())->toBeFalse();
});

it('redacts the token when a TokenSet is serialised', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-token',
            'refresh_token' => 'refresh-token-value',
            'expires_in' => 5183,
        ]),
    ]);

    $session = facebookProvider()->authenticate(new \App\Services\Social\Data\AuthRequest(
        provider: 'facebook',
        code: 'auth-code',
        state: 'state-value',
    ));

    $serialised = json_encode($session->tokens->toArray());

    expect($serialised)->not->toContain('short-lived-token')
        ->and($serialised)->not->toContain('refresh-token-value')
        ->and($serialised)->toContain('has_access_token":true');
});

it('verifies a webhook with a matching HMAC signature', function () {
    $body = json_encode(['object' => 'page', 'entry' => [['id' => '1234567890']]]);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'webhook-secret');

    $request = new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: $body,
        payload: json_decode($body, true),
        headers: ['X-Hub-Signature-256' => $signature, 'X-Hub-Signature-Timestamp' => (string) time()],
    );

    expect(facebookProvider()->verifyWebhook($request))->toBeTrue();
});

it('rejects a webhook with a bad signature', function () {
    $body = json_encode(['object' => 'page']);

    $request = new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: $body,
        payload: json_decode($body, true),
        headers: ['X-Hub-Signature-256' => 'sha256=deadbeef'],
    );

    try {
        facebookProvider()->verifyWebhook($request);
        $this->fail('Expected a WebhookSignatureException.');
    } catch (WebhookSignatureException $e) {
        expect($e->reason)->toBe(WebhookSignatureException::REASON_SIGNATURE)
            ->and($e->getMessage())->not->toContain('deadbeef');
    }
});

it('rejects a webhook signed with the wrong secret', function () {
    $body = json_encode(['object' => 'page']);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'attacker-secret');

    $request = new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: $body,
        payload: json_decode($body, true),
        headers: ['X-Hub-Signature-256' => $signature],
    );

    expect(fn () => facebookProvider()->verifyWebhook($request))
        ->toThrow(
            WebhookSignatureException::class,
            'Webhook from provider "facebook" failed verification (signature_mismatch).',
        );
});

it('rejects a webhook with no signature at all', function () {
    $request = new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: json_encode(['object' => 'page']),
        payload: ['object' => 'page'],
        headers: [],
    );

    try {
        facebookProvider()->verifyWebhook($request);
        $this->fail('Expected a WebhookSignatureException.');
    } catch (WebhookSignatureException $e) {
        expect($e->reason)->toBe(WebhookSignatureException::REASON_MISSING_SIGNATURE);
    }
});

it('rejects a replayed webhook outside the 5 minute tolerance', function () {
    $body = json_encode(['object' => 'page']);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'webhook-secret');

    $request = new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: $body,
        payload: json_decode($body, true),
        headers: [
            'X-Hub-Signature-256' => $signature,
            'X-Hub-Signature-Timestamp' => (string) (time() - 400),
        ],
    );

    expect(fn () => facebookProvider()->verifyWebhook($request))
        ->toThrow(
            WebhookSignatureException::class,
            'Webhook from provider "facebook" failed verification (timestamp_out_of_tolerance).',
        );
});

it('refuses webhook verification when no app secret is configured', function () {
    config()->set('socialhub.providers.facebook.oauth.webhook_secret', '');

    $request = new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: '{}',
        payload: [],
        headers: ['X-Hub-Signature-256' => 'sha256=anything'],
    );

    try {
        facebookProvider()->verifyWebhook($request);
        $this->fail('Expected a ProviderNotConfiguredException.');
    } catch (ProviderNotConfiguredException $e) {
        expect($e->provider)->toBe('facebook')
            ->and($e->getMessage())->toContain('webhook signatures');
    }
});

it('normalises a feed change webhook into the internal event shape', function () {
    $normalised = facebookProvider()->normalizeWebhook([
        'object' => 'page',
        'entry' => [[
            'id' => '1234567890',
            'changes' => [[
                'field' => 'feed',
                'value' => [
                    'verb' => 'add',
                    'created_time' => 1_700_000_000,
                    'from' => ['id' => 'u1'],
                    'post_id' => '9001',
                ],
            ]],
        ]],
    ]);

    expect($normalised['provider'])->toBe('facebook')
        ->and($normalised['event_type'])->toBe('feed')
        ->and($normalised['page_id'])->toBe('1234567890')
        ->and($normalised['changes'][0]['field'])->toBe('feed')
        ->and($normalised['changes'][0]['verb'])->toBe('add')
        ->and($normalised['changes'][0]['object_id'])->toBe('9001');
});

it('normalises a webhook with no entries without throwing', function () {
    $normalised = facebookProvider()->normalizeWebhook(['object' => 'instagram']);

    expect($normalised['provider'])->toBe('facebook')
        ->and($normalised['event_type'])->toBe('instagram')
        ->and($normalised['changes'])->toBe([])
        ->and($normalised['raw'])->toHaveKey('object');
});

it('refreshes the long-lived user token server-side', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token' => Http::response([
            'access_token' => 'refreshed-page-token',
            'expires_in' => 5183,
        ]),
    ]);

    $tokens = facebookProvider()->refreshToken(connectedPage());

    Http::assertSent(function ($request) {
        return $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'user-long-lived-token'
            && ! str_contains($request->url(), 'user-long-lived-token');
    });

    expect($tokens->accessToken)->toBe('refreshed-page-token');
});

it('keeps the refresh token when the provider omits it from the response', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token' => Http::response([
            'access_token' => 'refreshed-page-token',
            'expires_in' => 5183,
        ]),
    ]);

    $tokens = facebookProvider()->refreshToken(connectedPage());

    expect($tokens->refreshToken)->toBe('user-long-lived-token');
});

it('the token resolver reads the encrypted token relation', function () {
    $account = connectedPage();

    $resolver = new AccountTokenResolver;

    expect($resolver->resolve($account, 'facebook')->accessToken)->toBe('page-access-token');
});

it('the resolver refuses to invent a token when none is stored', function () {
    $tenant = Tenant::first() ?? Tenant::create(['name' => 'No Token', 'slug' => 'no-token-'.uniqid()]);

    $account = new SocialAccount([
        'tenant_id' => $tenant->id,
        'provider' => 'facebook',
        'provider_account_id' => '999',
        'account_type' => 'page',
        'status' => 'connected',
    ]);

    $account->save();

    expect(fn () => (new AccountTokenResolver)->resolve($account, 'facebook'))
        ->toThrow(\App\Exceptions\Social\TokenExpiredException::class);
});

it('stores the token as ciphertext, never plaintext', function () {
    connectedPage();

    $stored = DB::table('social_account_tokens')->value('access_token');

    expect($stored)->not->toBe('page-access-token')
        ->and($stored)->toBeString()->not->toBeEmpty();
});
