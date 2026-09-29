<?php

declare(strict_types=1);

use App\Exceptions\Social\AuthenticationException;
use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Exceptions\Social\WebhookSignatureException;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\Tenant;
use App\Services\Social\Data\AnalyticsQuery;
use App\Services\Social\Data\AuthRequest;
use App\Services\Social\Data\ProviderComment;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\ProviderHttpClient;
use App\Services\Social\ProviderRateLimiter;
use App\Services\Social\Providers\InstagramProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * Every outbound call is faked, so this suite asserts the exact Graph API
 * endpoints the provider targets without ever touching the network.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('socialhub.providers.instagram.credentials', [
        'client_id' => 'test-app-id',
        'client_secret' => 'test-app-secret',
        'webhook_verify_token' => 'verify-token',
    ]);

    config()->set('socialhub.providers.instagram.oauth.client_id', 'test-app-id');
    config()->set('socialhub.providers.instagram.oauth.client_secret', 'test-app-secret');
    config()->set('socialhub.providers.instagram.oauth.webhook_secret', 'ig-webhook-secret');

    // Most cases need a container that is ready immediately; the polling test
    // overrides these to exercise the bounded retry.
    config()->set('socialhub.providers.instagram.container_poll_attempts', 1);
    config()->set('socialhub.providers.instagram.container_poll_interval', 0);

    // A catch-all behind the specific patterns: an unmatched URL means a test
    // asserted the wrong endpoint, and must fail loudly rather than reach the
    // real Graph API.
    Http::preventStrayRequests();
});

function instagramProvider(): InstagramProvider
{
    return new InstagramProvider(
        http: new ProviderHttpClient(
            limiter: new ProviderRateLimiter(redis: null),
            provider: 'instagram',
        ),
    );
}

function instagramAccount(string $igId = '17841400000000000'): SocialAccount
{
    $tenant = Tenant::first() ?? Tenant::create(['name' => 'SocialHub Test', 'slug' => 'socialhub-test-'.uniqid()]);

    $account = new SocialAccount([
        'tenant_id' => $tenant->id,
        'provider' => 'instagram',
        'provider_account_id' => $igId.''.uniqid(),
        'provider_display_name' => 'Test IG',
        'account_type' => 'business',
        'status' => 'connected',
        'metadata' => ['granted_scopes' => ['instagram_basic', 'instagram_content_publish']],
    ]);

    $account->save();

    $token = new SocialAccountToken([
        'social_account_id' => $account->id,
        'access_token' => 'ig-user-token',
        'refresh_token' => 'ig-long-lived-token',
        'token_type' => 'Bearer',
    ]);

    $token->save();
    $account->setRelation('token', $token);

    return $account;
}

function instagramComment(string $commentId = 'c1'): ProviderComment
{
    return new ProviderComment(
        provider: 'instagram',
        providerCommentId: $commentId,
        providerPostId: 'm1',
        content: 'nice',
    );
}

function instagramAnalytics(array $postIds = [], array $metrics = []): AnalyticsQuery
{
    return new AnalyticsQuery(
        from: new DateTimeImmutable('2026-09-01'),
        to: new DateTimeImmutable('2026-09-28'),
        granularity: 'day',
        postIds: $postIds,
        metrics: $metrics,
    );
}

// ------------------------------------------------------------- capabilities

it('declares exactly what the Content Publishing API supports', function () {
    $capabilities = instagramProvider()->getSupportedFeatures();

    expect($capabilities->publishing)->toBeTrue()
        ->and($capabilities->imagePublishing)->toBeTrue()
        ->and($capabilities->videoPublishing)->toBeTrue()
        ->and($capabilities->carouselPublishing)->toBeTrue()
        ->and($capabilities->reels)->toBeTrue()
        ->and($capabilities->stories)->toBeTrue()
        ->and($capabilities->scheduling)->toBeFalse()
        // Instagram has no link post and no text-only post.
        ->and($capabilities->linkPosts)->toBeFalse()
        ->and($capabilities->textPublishing)->toBeFalse()
        // Media insights do report reach and impressions.
        ->and($capabilities->reach)->toBeTrue()
        ->and($capabilities->impressions)->toBeTrue()
        ->and($capabilities->deletePost)->toBeTrue()
        ->and($capabilities->webhooks)->toBeTrue();
});

it('refuses a text-only post with a message that tells the user what to attach', function () {
    try {
        instagramProvider()->createPost(instagramAccount(), ['text' => 'caption only']);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('textPublishing')
            ->and($e->userFacingError->userMessage)->toContain('photo')
            ->and($e->userFacingError->remediation)->toContain('media');
    }
});

it('refuses a scheduled post because Instagram has no publishAt field', function () {
    instagramProvider()->createPost(instagramAccount(), [
        'text' => 'later',
        'media_ids' => ['https://cdn.example.com/a.jpg'],
        'scheduled_at' => '2099-01-01 10:00:00',
    ]);
})->throws(UnsupportedCapabilityException::class);

// ------------------------------------------------------------- two-phase flow

it('creates a container, waits for FINISHED, then publishes it', function () {
    $order = [];

    Http::fake(function ($request) use (&$order) {
        $order[] = $request->url();

        return match (true) {
            str_contains($request->url(), '/media_publish') => Http::response(['id' => 'media-1'], 200),
            str_contains($request->url(), '/container-1') => Http::response(['status_code' => 'FINISHED'], 200),
            str_ends_with($request->url(), '/media') => Http::response(['id' => 'container-1'], 200),
            default => Http::response([], 404),
        };
    });

    $post = instagramProvider()->createPost(instagramAccount(), [
        'text' => 'hello world',
        'media_ids' => ['https://cdn.example.com/a.jpg'],
    ]);

    expect($post->providerPostId)->toBe('media-1')
        ->and($post->permalink)->toContain('media-1');

    // The real sequence is container -> status -> media_publish.
    expect($order)->toHaveCount(3)
        ->and($order[0])->toEndWith('/media')
        ->and($order[1])->toContain('/container-1')
        ->and($order[2])->toEndWith('/media_publish');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/media_publish')
        && $request['creation_id'] === 'container-1');
});

it('polls a container that is still processing and never publishes it blind', function () {
    config()->set('socialhub.providers.instagram.container_poll_attempts', 3);
    config()->set('socialhub.providers.instagram.container_poll_interval', 0);

    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'container-2'], 200),
        'graph.facebook.com/*/container-2*' => Http::response(['status_code' => 'IN_PROGRESS'], 200),
    ]);

    try {
        instagramProvider()->createPost(instagramAccount(), [
            'text' => 'slow',
            'media_ids' => ['https://cdn.example.com/a.mp4'],
            'content_type' => 'video',
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        dump($e->getMessage(), $e->status, $e->userFacingError->code);
        expect($e->userFacingError->code)->toBe('container_unfinished')
            ->and($e->userFacingError->retryable)->toBeTrue();
    }

    Http::assertSent(fn ($request) => str_contains($request->url(), '/container-2'));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/media_publish'));
});

it('creates a REELS container for a reel and a plain VIDEO container otherwise', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'container-3'], 200),
        'graph.facebook.com/*/container-3*' => Http::response(['status_code' => 'FINISHED'], 200),
        'graph.facebook.com/*/media_publish' => Http::response(['id' => 'media-3'], 200),
    ]);

    instagramProvider()->createPost(instagramAccount(), [
        'text' => 'a reel',
        'media_ids' => ['https://cdn.example.com/clip.mp4'],
        'content_type' => 'reel',
    ]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/media')
        && $request['media_type'] === 'REELS'
        && $request['video_url'] === 'https://cdn.example.com/clip.mp4');
});

it('builds a carousel from one child container per item', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['id' => 'parent-1'], 200),
        'graph.facebook.com/*/parent-1*' => Http::response(['status_code' => 'FINISHED'], 200),
        'graph.facebook.com/*/media_publish' => Http::response(['id' => 'media-4'], 200),
    ]);

    instagramProvider()->createPost(instagramAccount(), [
        'text' => 'a carousel',
        'media_ids' => ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'],
        'content_type' => 'carousel',
    ]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/media')
        && ($request['media_type'] ?? null) === 'CAROUSEL'
        && $request['children'] === ['child-1', 'child-2']);
});

it('refuses a carousel with fewer than two children instead of calling the API', function () {
    Http::fake(['graph.facebook.com/*/media' => Http::response(['id' => 'child-1'], 200)]);

    try {
        instagramProvider()->createPost(instagramAccount(), [
            'text' => 'one item',
            'media_ids' => ['https://cdn.example.com/a.jpg'],
            'content_type' => 'carousel',
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('carousel_too_small')
            ->and($e->userFacingError->remediation)->toContain('two or more');
    }

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/media_publish'));
});

it('requires a public media URL because Instagram fetches the media itself', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'x'], 200)]);

    try {
        instagramProvider()->createPost(instagramAccount(), [
            'text' => 'local file',
            'media_ids' => ['local-path-in-storage.jpg'],
        ]);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('publicMediaUrl')
            ->and($e->userFacingError->remediation)->toContain('public HTTPS URL');
    }
});

it('reads the account and its follower count from the Graph API', function () {
    Http::fake([
        'graph.facebook.com/*/*' => Http::response([
            'id' => '17841400000000000',
            'username' => 'brand',
            'name' => 'Brand',
            'followers_count' => 4242,
        ], 200),
    ]);

    $account = instagramAccount('17841400000000000');

    $profile = instagramProvider()->getAccount($account);

    expect($profile->providerAccountId)->toBe('17841400000000000')
        ->and($profile->username)->toBe('brand')
        ->and($profile->followerCount)->toBe(4242);

    expect(instagramProvider()->getFollowers($account)->total)->toBe(4242);
});

it('lists only the Pages that have a linked professional account', function () {
    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [
                ['id' => 'page-1', 'name' => 'With IG', 'instagram_business_account' => ['id' => 'ig-1', 'username' => 'one']],
                ['id' => 'page-2', 'name' => 'No IG'],
            ],
            'paging' => ['cursors' => ['after' => null]],
        ], 200),
    ]);

    $pages = instagramProvider()->getPages(instagramAccount());

    expect($pages)->toHaveCount(1)
        ->and($pages[0]->providerAccountId)->toBe('ig-1')
        ->and($pages[0]->raw['page_id'])->toBe('page-1');
});

it('replies to a comment through the replies edge', function () {
    Http::fake(['graph.facebook.com/*/c1/replies' => Http::response(['id' => 'r1'], 200)]);

    instagramProvider()->replyToComment(instagramAccount(), instagramComment(), 'thanks');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/c1/replies')
        && $request['message'] === 'thanks');
});

it('normalises media insights into the shared metric vocabulary', function () {
    Http::fake([
        'graph.facebook.com/*/m1/insights*' => Http::response([
            'data' => [
                ['name' => 'views', 'values' => [['value' => 1200, 'end_time' => '2026-09-28']]],
                ['name' => 'reach', 'values' => [['value' => 800, 'end_time' => '2026-09-28']]],
                ['name' => 'saved', 'values' => [['value' => 15, 'end_time' => '2026-09-28']]],
            ],
        ], 200),
        // The account-level window legitimately has no data for this period.
        'graph.facebook.com/*/*/insights*' => Http::response([], 400),
    ]);

    $batch = instagramProvider()->getAnalytics(instagramAccount(), instagramAnalytics(['m1']));

    $metrics = collect($batch->postMetrics['m1'])->keyBy('metric');

    expect($metrics['views']->value)->toBe(1200.0)
        ->and($metrics['reach']->value)->toBe(800.0)
        ->and($metrics['saves']->value)->toBe(15.0)
        // impressions was not reported, so it is absent rather than zero.
        ->and($metrics->has('impressions'))->toBeFalse();
});

// -------------------------------------------------------------------- errors

it('turns a 429 into a rate limit exception carrying the real retry delay', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response([
            'error' => ['code' => 4, 'message' => 'Application request limit reached'],
        ], 429, ['Retry-After' => '120']),
    ]);

    try {
        instagramProvider()->createPost(instagramAccount(), [
            'text' => 'throttled',
            'media_ids' => ['https://cdn.example.com/a.jpg'],
        ]);
        $this->fail('Expected a RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(120)
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

it('maps the rolling publish cap to a retryable user-facing error', function () {
    // Graph reports the content publishing cap as error 36003 on a 400, not as
    // a 429, so it arrives through the error map rather than the throttle path.
    Http::fake([
        'graph.facebook.com/*/media' => Http::response([
            'error' => ['code' => 36003, 'message' => 'Post limit reached'],
        ], 400),
    ]);

    try {
        instagramProvider()->createPost(instagramAccount(), [
            'text' => 'capped',
            'media_ids' => ['https://cdn.example.com/a.jpg'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('api_post_cap')
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

it('maps an expired token to a revoked connection', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response([
            'error' => ['code' => 190, 'message' => 'Invalid OAuth access token'],
        ], 401),
    ]);

    instagramProvider()->createPost(instagramAccount(), [
        'text' => 'expired',
        'media_ids' => ['https://cdn.example.com/a.jpg'],
    ]);
})->throws(TokenRevokedException::class);

it('maps a permission failure to a reconnect message', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response([
            'error' => ['code' => 200, 'message' => 'Permissions error'],
        ], 403),
    ]);

    try {
        instagramProvider()->createPost(instagramAccount(), [
            'text' => 'no permission',
            'media_ids' => ['https://cdn.example.com/a.jpg'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('permission_missing')
            ->and($e->userFacingError->retryable)->toBeFalse()
            ->and($e->userFacingError->remediation)->toContain('Reconnect');
    }
});

it('maps an unlinked Instagram account to a Business Manager remediation', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response([
            'error' => ['code' => 9, 'message' => 'Instagram API is not yet enabled'],
        ], 400),
    ]);

    try {
        instagramProvider()->createPost(instagramAccount(), [
            'text' => 'unlinked',
            'media_ids' => ['https://cdn.example.com/a.jpg'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('instagram_account_issue')
            ->and($e->userFacingError->remediation)->toContain('Link the Instagram professional account');
    }
});

it('surfaces a container Instagram reported as expired as retryable', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'container-9'], 200),
        'graph.facebook.com/*/container-9*' => Http::response(['status_code' => 'EXPIRED'], 200),
    ]);

    try {
        instagramProvider()->createPost(instagramAccount(), [
            'text' => 'expired container',
            'media_ids' => ['https://cdn.example.com/a.jpg'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('container_expired')
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

// ------------------------------------------------------------------ webhooks

it('accepts a webhook signed with the app secret', function () {
    $body = (string) json_encode(['object' => 'instagram', 'entry' => [['id' => 'page-1', 'changes' => [
        ['field' => 'comments', 'value' => ['verb' => 'add', 'media_id' => 'm1', 'from' => ['id' => 'u1']]],
    ]]]]);

    $signature = 'sha256='.hash_hmac('sha256', $body, 'ig-webhook-secret');

    $request = new VerifiedWebhookRequest(
        provider: 'instagram',
        headers: ['X-Hub-Signature-256' => $signature],
        payload: json_decode($body, true),
        rawBody: $body,
    );

    expect(instagramProvider()->verifyWebhook($request))->toBeTrue();

    $normalized = instagramProvider()->normalizeWebhook(json_decode($body, true));

    expect($normalized['provider'])->toBe('instagram')
        ->and($normalized['event_type'])->toBe('comments')
        ->and($normalized['changes'][0]['object_id'])->toBe('m1');
});

it('rejects a webhook whose signature does not match', function () {
    $request = new VerifiedWebhookRequest(
        provider: 'instagram',
        headers: ['X-Hub-Signature-256' => 'sha256=deadbeef'],
        payload: ['object' => 'instagram'],
        rawBody: '{"object":"instagram"}',
    );

    instagramProvider()->verifyWebhook($request);
})->throws(WebhookSignatureException::class);

it('rejects a webhook with no signature at all', function () {
    $request = new VerifiedWebhookRequest(
        provider: 'instagram',
        headers: [],
        payload: ['object' => 'instagram'],
        rawBody: '{"object":"instagram"}',
    );

    instagramProvider()->verifyWebhook($request);
})->throws(WebhookSignatureException::class, 'missing');

// ---------------------------------------------------------------------- auth

it('builds an authorization URL that asks for the publishing scopes', function () {
    $session = instagramProvider()->authenticate(new AuthRequest(
        provider: 'instagram',
        state: 'state-123',
        redirectUri: 'https://app.test/callback',
    ));

    expect($session->authorizationUrl)->toContain('https://www.facebook.com/')
        ->and($session->authorizationUrl)->toContain('client_id=test-app-id')
        ->and($session->authorizationUrl)->toContain('instagram_content_publish')
        ->and($session->authorizationUrl)->toContain('instagram_manage_insights')
        ->and($session->authorizationUrl)->toContain('state=state-123');
});

it('reports a missing app id as a configuration failure, not a fake success', function () {
    config()->set('socialhub.providers.instagram.credentials', [
        'client_id' => null,
        'client_secret' => null,
    ]);

    instagramProvider()->getAccount(instagramAccount());
})->throws(ProviderNotConfiguredException::class, 'META_APP_ID');

it('treats a rejected authorization code as an authentication failure', function () {
    Http::fake(['*/oauth/access_token' => Http::response([
        'error' => ['code' => 100, 'error_subcode' => 46320001, 'message' => 'The authorization code is invalid'],
    ], 400)]);

    instagramProvider()->refreshToken(instagramAccount());
})->throws(AuthenticationException::class);
