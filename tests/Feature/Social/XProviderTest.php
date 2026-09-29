<?php

declare(strict_types=1);

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\Tenant;
use App\Services\Social\Data\AnalyticsQuery;
use App\Services\Social\Data\AuthRequest;
use App\Services\Social\Data\ProviderComment;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\ProviderHttpClient;
use App\Services\Social\ProviderRateLimiter;
use App\Services\Social\Providers\XProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Every outbound call is faked, so this suite asserts the real X API v2
 * endpoints — including the chunked media upload — without touching the network.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('socialhub.providers.x.credentials', [
        'client_id' => 'x-client-id',
        'client_secret' => 'x-client-secret',
    ]);

    config()->set('socialhub.providers.x.oauth.client_id', 'x-client-id');
    config()->set('socialhub.providers.x.oauth.client_secret', 'x-client-secret');
    config()->set('socialhub.providers.x.oauth.token_url', 'https://api.x.com/2/oauth2/token');
    config()->set('socialhub.providers.x.oauth.authorize_url', 'https://x.com/i/oauth2/authorize');

    Http::preventStrayRequests();
});

function xProvider(): XProvider
{
    return new XProvider(
        http: new ProviderHttpClient(
            limiter: new ProviderRateLimiter(redis: null),
            provider: 'x',
        ),
    );
}

function xAccount(): SocialAccount
{
    $tenant = Tenant::first() ?? Tenant::create(['name' => 'SocialHub Test', 'slug' => 'socialhub-test-'.uniqid()]);

    $account = new SocialAccount([
        'tenant_id' => $tenant->id,
        'provider' => 'x',
        'provider_account_id' => 'user-1'.uniqid(),
        'provider_display_name' => 'Test User',
        'account_type' => 'user',
        'status' => 'connected',
        'metadata' => [
            'user_id' => '12345',
            'granted_scopes' => ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'],
        ],
    ]);

    $account->save();

    $token = new SocialAccountToken([
        'social_account_id' => $account->id,
        'access_token' => 'x-access-token',
        'refresh_token' => 'x-refresh-token',
        'token_type' => 'Bearer',
    ]);

    $token->save();
    $account->setRelation('token', $token);

    return $account;
}

function xMedia(string $mime = 'image/jpeg', int $bytes = 2048): MediaAsset
{
    Storage::fake('local');
    Storage::disk('local')->put('media/asset.bin', str_repeat('a', min($bytes, 4096)));

    $media = new MediaAsset([
        'tenant_id' => Tenant::firstOrFail()->id,
        'user_id' => \App\Models\User::factory()->create()->id,
        'filename' => 'asset.bin',
        'stored_filename' => 'asset.bin',
        'mime_type' => $mime,
        'file_size' => $bytes,
        'storage_disk' => 'local',
        'storage_path' => 'media/asset.bin',
    ]);

    $media->save();

    return $media;
}

function xAnalytics(array $postIds = [], array $metrics = []): AnalyticsQuery
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

it('declares no reach and no views because X reports neither', function () {
    $capabilities = xProvider()->getSupportedFeatures();

    expect($capabilities->textPublishing)->toBeTrue()
        // media.write supports video upload through the v2 chunked protocol.
        ->and($capabilities->videoPublishing)->toBeTrue()
        // The v2 API has no read endpoint for the replies to a Post.
        ->and($capabilities->comments)->toBeFalse()
        // A reply is just a Post with in_reply_to_tweet_id.
        ->and($capabilities->commentReplies)->toBeTrue()
        ->and($capabilities->reach)->toBeFalse()
        ->and($capabilities->views)->toBeFalse()
        ->and($capabilities->impressions)->toBeTrue()
        ->and($capabilities->scheduling)->toBeFalse();
});

it('refuses to read comments because the v2 API has no such endpoint', function () {
    try {
        xProvider()->getComments(xAccount(), '2000');
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('comments');
    }

    Http::assertNothingSent();
});

it('refuses a scheduled publish because X has no scheduling endpoint', function () {
    try {
        xProvider()->createPost(xAccount(), [
            'text' => 'later',
            'scheduled_at' => (new DateTimeImmutable('+2 days'))->format(DATE_ATOM),
        ]);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('scheduling');
    }

    Http::assertNothingSent();
});

it('refuses a carousel because a Post cannot mix or exceed four images', function () {
    try {
        xProvider()->createPost(xAccount(), [
            'text' => 'many pictures',
            'media_ids' => ['m1', 'm2', 'm3', 'm4', 'm5'],
            'content_type' => 'carousel',
        ]);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('carouselPublishing');
    }

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- publishing

it('publishes a text-only Post through the v2 tweets endpoint', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response([
        'data' => ['id' => '2001', 'text' => 'hello world'],
    ], 201)]);

    $post = xProvider()->createPost(xAccount(), ['text' => 'hello world']);

    expect($post->providerPostId)->toBe('2001')
        ->and($post->permalink)->toBe('https://x.com/i/web/status/2001');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://api.x.com/2/tweets'
            && $body['text'] === 'hello world'
            && ! isset($body['media']);
    });
});

it('attaches uploaded media ids to the Post', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '2002']], 201)]);

    xProvider()->createPost(xAccount(), [
        'text' => 'with a picture',
        'media_ids' => ['media-1', 'media-2'],
        'content_type' => 'image',
    ]);

    Http::assertSent(fn ($request) => ($request->data())['media']['media_ids'] === ['media-1', 'media-2']);
});

it('posts a reply with in_reply_to_tweet_id rather than through a reply endpoint', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '2003']], 201)]);

    $comment = new ProviderComment(
        provider: 'x',
        providerCommentId: '2001',
        providerPostId: '2000',
        content: 'the original post',
    );

    xProvider()->replyToComment(xAccount(), $comment, 'my answer');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://api.x.com/2/tweets'
            && $body['reply']['in_reply_to_tweet_id'] === '2000'
            && $body['text'] === 'my answer';
    });
});

it('deletes a Post through the v2 tweets delete endpoint', function () {
    Http::fake(['https://api.x.com/2/tweets/2001' => Http::response(['data' => ['deleted' => true]], 200)]);

    expect(xProvider()->deletePost(xAccount(), '2001'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.x.com/2/tweets/2001');
});

// ------------------------------------------------------------ media uploading

it('runs the chunked v2 media upload and returns the media id', function () {
    $calls = [];

    Http::fake(function ($request) use (&$calls) {
        $calls[] = $request->method().' '.$request->url();

        return match (true) {
            str_contains($request->url(), '/2/media/upload/initialize') => Http::response(['data' => ['id' => 'media-1']], 202),
            str_contains($request->url(), '/append') => Http::response('', 204),
            str_contains($request->url(), '/finalize') => Http::response(['data' => ['media_id_string' => 'media-1']], 201),
            default => Http::response([], 404),
        };
    });

    $mediaId = xProvider()->uploadMedia(xAccount(), xMedia());

    expect($mediaId)->toBe('media-1')
        ->and($calls[0])->toContain('POST https://api.x.com/2/media/upload/initialize')
        ->and($calls[1])->toContain('POST https://api.x.com/2/media/upload/media-1/append')
        ->and($calls[2])->toContain('POST https://api.x.com/2/media/upload/media-1/finalize');

    // The chunk really is base64 of the stored bytes, with a segment index.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/append')
        && $request['segment_index'] === '0'
        && base64_decode($request['media']) === str_repeat('a', 2048));
});

it('uploads video through the same protocol and waits for processing', function () {
    config()->set('socialhub.providers.x.media_processing_attempts', 2);

    Http::fake([
        'https://api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => 'vid-1']], 202),
        'https://api.x.com/2/media/upload/vid-1/append' => Http::response('', 204),
        'https://api.x.com/2/media/upload/vid-1/finalize' => Http::response([
            'data' => [
                'media_id_string' => 'vid-1',
                'processing_info' => ['state' => 'succeeded', 'check_after_secs' => 1],
            ],
        ], 201),
    ]);

    $mediaId = xProvider()->uploadMedia(xAccount(), xMedia(mime: 'video/mp4'));

    expect($mediaId)->toBe('vid-1');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && ($request->data())['media_category'] === 'tweet_video');
});

it('reports a media processing failure instead of attaching unusable media', function () {
    Http::fake([
        'https://api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => 'vid-2']], 202),
        'https://api.x.com/2/media/upload/vid-2/append' => Http::response('', 204),
        'https://api.x.com/2/media/upload/vid-2/finalize' => Http::response([
            'data' => [
                'processing_info' => ['state' => 'failed', 'error' => ['message' => 'Unsupported codec']],
            ],
        ], 201),
    ]);

    try {
        xProvider()->uploadMedia(xAccount(), xMedia(mime: 'video/mp4'));
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('media_processing_failed')
            ->and($e->userFacingError->retryable)->toBeFalse();
    }
});

it('refuses a file larger than the platform limit before uploading it', function () {
    Http::fake();

    try {
        xProvider()->uploadMedia(xAccount(), xMedia(bytes: 9_000_000_000));
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('media_too_large');
    }

    Http::assertNothingSent();
});

// -------------------------------------------------------------------- account

it('reads the authenticated user and their follower count', function () {
    Http::fake(['https://api.x.com/2/users/me*' => Http::response([
        'data' => [
            'id' => '12345',
            'name' => 'Test User',
            'username' => 'testuser',
            'profile_image_url' => 'https://pbs.twimg.com/a.jpg',
            'public_metrics' => ['followers_count' => 1500, 'following_count' => 200],
        ],
    ], 200)]);

    $account = xAccount();

    $profile = xProvider()->getAccount($account);

    expect($profile->displayName)->toBe('Test User')
        ->and($profile->username)->toBe('testuser')
        ->and($profile->followerCount)->toBe(1500)
        ->and($profile->profileUrl)->toBe('https://x.com/testuser');
});

it('reads the follower total from the dedicated count endpoint', function () {
    Http::fake(['https://api.x.com/2/users/12345/followers/count' => Http::response([
        'data' => ['followers_count' => 1500],
    ], 200)]);

    $stats = xProvider()->getFollowers(xAccount());

    expect($stats->total)->toBe(1500)
        ->and($stats->providerAccountId)->toBe('12345');
});

it('reports engagement counters from public_metrics and no reach or views', function () {
    Http::fake([
        'https://api.x.com/2/tweets/2001*' => Http::response([
            'data' => [
                'id' => '2001',
                'text' => 'a post',
                'created_at' => '2026-09-20T10:00:00.000Z',
                'public_metrics' => [
                    'like_count' => 40,
                    'reply_count' => 6,
                    'retweet_count' => 3,
                    'impression_count' => 5000,
                ],
            ],
        ], 200),
        'https://api.x.com/2/users/me*' => Http::response([
            'data' => ['public_metrics' => ['followers_count' => 1500]],
        ], 200),
    ]);

    $batch = xProvider()->getAnalytics(xAccount(), xAnalytics(['2001']));
    $metrics = collect($batch->postMetrics['2001'])->keyBy('metric');

    expect($metrics['likes']->value)->toBe(40.0)
        ->and($metrics['comments']->value)->toBe(6.0)
        ->and($metrics['impressions']->value)->toBe(5000.0);

    // Only a follower total is available at the user level; it is never a sum
    // of the per-post counters above.
    expect(collect($batch->accountMetrics)->keyBy('metric')->keys()->all())->toBe(['followers']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/2/tweets/2001')
        && str_contains($request->url(), 'public_metrics'));
});

// ------------------------------------------------------------------ webhooks

it('refuses webhook verification because X has no signed webhook delivery', function () {
    try {
        xProvider()->verifyWebhook(new VerifiedWebhookRequest(
            provider: 'x',
            headers: ['X-Twitter-Webhooks-Signature' => 'sha256=abc'],
            payload: [],
            rawBody: '{}',
        ));
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('webhooks');
    }
});

// -------------------------------------------------------------------- errors

it('turns a 429 into a rate limit exception with the real delay', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response([
        'title' => 'Too Many Requests',
        'detail' => 'Too Many Requests',
        'type' => 'about:blank',
        'code' => 429,
    ], 429, ['x-rate-limit-reset' => (string) (time() + 45)])]);

    try {
        xProvider()->createPost(xAccount(), ['text' => 'throttled']);
        $this->fail('Expected a RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBeGreaterThan(0)
            ->and($e->retryAfter)->toBeLessThanOrEqual(60)
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

it('maps a duplicate post to a change-the-wording error', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response([
        'title' => 'Duplicate Post',
        'detail' => 'duplicate',
        'code' => 403,
    ], 403)]);

    try {
        xProvider()->createPost(xAccount(), ['text' => 'same words again']);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('duplicate_post');
    }
});

it('maps an expired media id to an upload-again error', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response([
        'title' => 'Not Found Error',
        'detail' => 'media_not_found',
        'code' => 404,
    ], 404)]);

    try {
        xProvider()->createPost(xAccount(), ['text' => 'stale media', 'media_ids' => ['old-1']]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('media_expired')
            ->and($e->userFacingError->remediation)->toContain('again');
    }
});

it('maps insufficient scope to a reconnect error', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response([
        'title' => 'Unauthorized',
        'detail' => 'insufficient_scope',
        'code' => 403,
    ], 403)]);

    try {
        xProvider()->createPost(xAccount(), ['text' => 'no permission']);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('permission_missing')
            ->and($e->userFacingError->remediation)->toContain('tweet.write');
    }
});

// ---------------------------------------------------------------------- auth

it('sends PKCE parameters when a code verifier is supplied', function () {
    $session = xProvider()->authenticate(new AuthRequest(
        provider: 'x',
        state: 'state-1',
        redirectUri: 'https://app.test/callback',
        codeVerifier: 'verifier-value-1234567890',
    ));

    expect($session->authorizationUrl)->toContain('x.com/i/oauth2/authorize')
        ->and($session->authorizationUrl)->toContain('code_challenge=')
        ->and($session->authorizationUrl)->toContain('code_challenge_method=S256')
        ->and($session->authorizationUrl)->toContain('tweet.write');
});

it('never puts the client secret in the authorization url', function () {
    $session = xProvider()->authenticate(new AuthRequest(
        provider: 'x',
        state: 'state-1',
        redirectUri: 'https://app.test/callback',
    ));

    expect($session->authorizationUrl)->not->toContain('x-client-secret');
});

it('persists the rotated refresh token X returns on every refresh', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response([
        'token_type' => 'Bearer',
        'expires_in' => 7200,
        'access_token' => 'x-access-token-2',
        'refresh_token' => 'x-refresh-token-2',
        'scope' => 'tweet.read tweet.write',
    ], 200)]);

    $tokens = xProvider()->refreshToken(xAccount());

    expect($tokens->accessToken)->toBe('x-access-token-2')
        // X invalidates the previous refresh token, so the new one must be kept.
        ->and($tokens->refreshToken)->toBe('x-refresh-token-2');
});

it('treats an invalid_grant refresh as a revoked connection', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'The refresh token is invalid or expired.',
    ], 400)]);

    try {
        xProvider()->refreshToken(xAccount());
        $this->fail('Expected a TokenRevokedException.');
    } catch (TokenRevokedException $e) {
        expect($e->provider)->toBe('x');
    }
});

it('treats a connection with no refresh token as unrecoverable', function () {
    $account = xAccount();
    $account->token->update(['refresh_token' => null]);
    $account->setRelation('token', $account->token->fresh());

    xProvider()->refreshToken($account);
})->throws(TokenRevokedException::class);

it('reports missing credentials instead of pretending to connect', function () {
    config()->set('socialhub.providers.x.credentials', [
        'client_id' => null,
        'client_secret' => null,
    ]);

    xProvider()->getAccount(xAccount());
})->throws(ProviderNotConfiguredException::class, 'X_CLIENT_ID');
