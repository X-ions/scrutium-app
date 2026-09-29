<?php

declare(strict_types=1);

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Exceptions\Social\WebhookSignatureException;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\Tenant;
use App\Services\Social\Data\AnalyticsQuery;
use App\Services\Social\Data\AuthRequest;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\ProviderHttpClient;
use App\Services\Social\ProviderRateLimiter;
use App\Services\Social\Providers\TikTokProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Every outbound call is faked, so this suite asserts the real TikTok Content
 * Posting API endpoints and error codes without ever touching the network.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('socialhub.providers.tiktok.credentials', [
        'client_key' => 'tt-client-key',
        'client_secret' => 'tt-client-secret',
        'webhook_secret' => 'tt-webhook-secret',
    ]);

    config()->set('socialhub.providers.tiktok.oauth.client_id', 'tt-client-key');
    config()->set('socialhub.providers.tiktok.oauth.client_secret', 'tt-client-secret');
    config()->set('socialhub.providers.tiktok.oauth.webhook_secret', 'tt-webhook-secret');

    Http::preventStrayRequests();
});

function tiktokProvider(): TikTokProvider
{
    return new TikTokProvider(
        http: new ProviderHttpClient(
            limiter: new ProviderRateLimiter(redis: null),
            provider: 'tiktok',
        ),
    );
}

function tiktokAccount(): SocialAccount
{
    $tenant = Tenant::first() ?? Tenant::create(['name' => 'SocialHub Test', 'slug' => 'socialhub-test-'.uniqid()]);

    $account = new SocialAccount([
        'tenant_id' => $tenant->id,
        'provider' => 'tiktok',
        'provider_account_id' => 'open-id-1'.uniqid(),
        'provider_display_name' => 'Test Creator',
        'account_type' => 'creator',
        'status' => 'connected',
        'metadata' => ['granted_scopes' => ['user.info.basic', 'video.publish']],
    ]);

    $account->save();

    $token = new SocialAccountToken([
        'social_account_id' => $account->id,
        'access_token' => 'tt-access-token',
        'refresh_token' => 'tt-refresh-token',
        'token_type' => 'Bearer',
    ]);

    $token->save();
    $account->setRelation('token', $token);

    return $account;
}

function tiktokMedia(string $url = 'https://media.example.com/clip.mp4', int $bytes = 2048): MediaAsset
{
    Storage::fake('local');
    Storage::disk('local')->put('videos/clip.mp4', str_repeat('a', 1024));

    $media = new MediaAsset([
        'tenant_id' => Tenant::firstOrFail()->id,
        'user_id' => \App\Models\User::factory()->create()->id,
        'filename' => 'clip.mp4',
        'stored_filename' => 'clip.mp4',
        'mime_type' => 'video/mp4',
        'file_size' => $bytes,
        'storage_disk' => 'local',
        'storage_path' => 'videos/clip.mp4',
        'metadata' => ['public_url' => $url],
    ]);

    $media->save();

    return $media;
}

function tiktokAnalytics(array $postIds = [], array $metrics = []): AnalyticsQuery
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

it('declares no native scheduling and no delete, because the Open Platform has neither', function () {
    $capabilities = tiktokProvider()->getSupportedFeatures();

    expect($capabilities->publishing)->toBeTrue()
        ->and($capabilities->videoPublishing)->toBeTrue()
        ->and($capabilities->carouselPublishing)->toBeTrue()
        // The Content Posting API has no scheduled_publish parameter at all.
        ->and($capabilities->scheduling)->toBeFalse()
        // TikTok does not allow deleting published videos through the API.
        ->and($capabilities->deletePost)->toBeFalse()
        // /v2/video/query/ returns view, like, comment and share counts only.
        ->and($capabilities->reach)->toBeFalse()
        ->and($capabilities->impressions)->toBeFalse()
        ->and($capabilities->webhooks)->toBeTrue();
});

it('refuses a scheduled publish because there is no such parameter', function () {
    try {
        tiktokProvider()->createPost(tiktokAccount(), [
            'text' => 'later',
            'media_ids' => ['https://media.example.com/clip.mp4'],
            'scheduled_at' => (new DateTimeImmutable('+2 days'))->format(DATE_ATOM),
        ]);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('scheduling');
    }

    Http::assertNothingSent();
});

it('refuses to delete a published video because TikTok offers no such endpoint', function () {
    try {
        tiktokProvider()->deletePost(tiktokAccount(), 'vid-1');
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('deletePost');
    }

    Http::assertNothingSent();
});

it('refuses to pull media from an unverified URL', function () {
    try {
        tiktokProvider()->createPost(tiktokAccount(), [
            'text' => 'no url',
            'media_ids' => ['not-a-url'],
        ]);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->userFacingError->userMessage)->toContain('verified HTTPS URL');
    }

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- publishing

it('publishes a video through the direct post init endpoint', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/video/init/*' => Http::response([
        'data' => ['publish_id' => 'pub-1'],
    ], 200)]);

    $post = tiktokProvider()->createPost(tiktokAccount(), [
        'text' => 'my caption',
        'media_ids' => ['https://media.example.com/clip.mp4'],
    ]);

    expect($post->providerPostId)->toBe('pub-1');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && str_contains($request->url(), '/v2/post/publish/video/init/')
            && $body['post_info']['title'] === 'my caption'
            && $body['post_info']['privacy_level'] === 'SELF_ONLY'
            && $body['source_info']['source'] === 'PULL_FROM_URL'
            && $body['source_info']['video_url'] === 'https://media.example.com/clip.mp4';
    });
});

it('publishes a multi-image carousel through the content init endpoint', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/content/init/*' => Http::response([
        'data' => ['publish_id' => 'pub-2'],
    ], 200)]);

    tiktokProvider()->createPost(tiktokAccount(), [
        'text' => 'a carousel',
        'media_ids' => [
            'https://media.example.com/1.jpg',
            'https://media.example.com/2.jpg',
        ],
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/v2/post/publish/content/init/')
            && $body['media_type'] === 'PHOTO'
            && $body['post_mode'] === 'DIRECT_POST'
            && $body['source_info']['photo_images'] === [
                'https://media.example.com/1.jpg',
                'https://media.example.com/2.jpg',
            ];
    });
});

it('sends a chunked file upload when the source is FILE_UPLOAD', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/video/init/*' => Http::response([
        'data' => ['publish_id' => 'pub-3'],
    ], 200)]);

    tiktokProvider()->createPost(tiktokAccount(), [
        'text' => 'chunked',
        'media_ids' => ['https://media.example.com/clip.mp4'],
        'options' => ['source' => 'FILE_UPLOAD', 'video_size' => 20971520],
    ]);

    Http::assertSent(function ($request) {
        $source = ($request->data())['source_info'] ?? [];

        return $source['source'] === 'FILE_UPLOAD'
            && $source['video_size'] === 20971520
            && $source['chunk_size'] === 10485760
            && $source['total_chunk_count'] === 2;
    });
});

it('refuses a public privacy level while the app is unaudited', function () {
    try {
        tiktokProvider()->createPost(tiktokAccount(), [
            'text' => 'public please',
            'media_ids' => ['https://media.example.com/clip.mp4'],
            'options' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->providerCode)->toBe('unaudited_client_can_only_post_to_private_accounts')
            ->and($e->userFacingError->code)->toBe('app_audit_required');
    }

    Http::assertNothingSent();
});

it('allows a public privacy level once the app is audited', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/video/init/*' => Http::response([
        'data' => ['publish_id' => 'pub-4'],
    ], 200)]);

    tiktokProvider()->createPost(tiktokAccount(), [
        'text' => 'public',
        'media_ids' => ['https://media.example.com/clip.mp4'],
        'options' => ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'app_audited' => true],
    ]);

    Http::assertSent(fn ($request) => ($request->data())['post_info']['privacy_level'] === 'PUBLIC_TO_EVERYONE');
});

it('creates an inbox draft through the upload endpoint', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/inbox/video/init/*' => Http::response([
        'data' => ['publish_id' => 'draft-1'],
    ], 200)]);

    $publishId = tiktokProvider()->uploadMedia(tiktokAccount(), tiktokMedia());

    expect($publishId)->toBe('draft-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/post/publish/inbox/video/init/')
        && ($request->data())['source_info']['video_url'] === 'https://media.example.com/clip.mp4');
});

it('rejects an init response that carries no publish id rather than reporting success', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/video/init/*' => Http::response([
        'data' => [],
    ], 200)]);

    try {
        tiktokProvider()->createPost(tiktokAccount(), [
            'text' => 'no id',
            'media_ids' => ['https://media.example.com/clip.mp4'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('publish_id_missing')
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

// -------------------------------------------------------------------- account

it('reads the creator profile and follower count from the user info endpoint', function () {
    Http::fake(['https://open.tiktokapis.com/v2/user/info/*' => Http::response([
        'data' => ['user' => [
            'open_id' => 'open-id-1',
            'username' => 'testcreator',
            'display_name' => 'Test Creator',
            'follower_count' => 4200,
        ]],
    ], 200)]);

    $account = tiktokAccount();

    $profile = tiktokProvider()->getAccount($account);

    expect($profile->displayName)->toBe('Test Creator')
        ->and($profile->username)->toBe('testcreator')
        ->and($profile->followerCount)->toBe(4200)
        ->and($profile->profileUrl)->toBe('https://www.tiktok.com/@testcreator');

    expect(tiktokProvider()->getFollowers($account)->total)->toBe(4200);
});

it('reads a video and its lifetime counters from the video query endpoint', function () {
    Http::fake(['https://open.tiktokapis.com/v2/video/query/*' => Http::response([
        'data' => ['videos' => [[
            'id' => 'vid-1',
            'title' => 'A clip',
            'share_url' => 'https://www.tiktok.com/@t/video/1',
            'view_count' => 900,
            'like_count' => 80,
            'comment_count' => 6,
            'share_count' => 3,
        ]]],
    ], 200)]);

    $post = tiktokProvider()->getPost(tiktokAccount(), 'vid-1');

    expect($post->permalink)->toBe('https://www.tiktok.com/@t/video/1');

    $batch = tiktokProvider()->getAnalytics(tiktokAccount(), tiktokAnalytics(['vid-1']));
    $metrics = collect($batch->postMetrics['vid-1'])->keyBy('metric');

    expect($metrics['views']->value)->toBe(900.0)
        ->and($metrics['likes']->value)->toBe(80.0)
        ->and($metrics['shares']->value)->toBe(3.0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/video/query/')
        && str_contains($request->url(), 'video_ids=vid-1'));
});

// ------------------------------------------------------------------ comments

it('reads comments and replies through the comment endpoints', function () {
    Http::fake([
        'https://open.tiktokapis.com/v2/comment/list/*' => Http::response([
            'comments' => [
                'has_more' => false,
                'comments' => [[
                    'cid' => 'c1',
                    'text' => 'love this',
                    'digg_count' => 14,
                    'create_time' => 1758000000,
                    'user' => ['open_id' => 'u1', 'username' => 'fan'],
                ]],
            ],
        ], 200),
        'https://open.tiktokapis.com/v2/comment/reply/*' => Http::response(['data' => ['comment_id' => 'r1']], 200),
    ]);

    $comments = tiktokProvider()->getComments(tiktokAccount(), 'vid-1');

    expect($comments)->toHaveCount(1)
        ->and($comments[0]->providerCommentId)->toBe('c1')
        ->and($comments[0]->likeCount)->toBe(14)
        ->and($comments[0]->authorUsername)->toBe('fan');

    tiktokProvider()->replyToComment(tiktokAccount(), $comments[0], 'thanks!');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/v2/comment/reply/')
            && $body['video_id'] === 'vid-1'
            && $body['comment_id'] === 'c1'
            && $body['text'] === 'thanks!';
    });
});

it('refuses an account-wide comment read because the endpoint is per video', function () {
    try {
        tiktokProvider()->getComments(tiktokAccount());
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->userFacingError->userMessage)->toContain('per video only');
    }

    Http::assertNothingSent();
});

// ------------------------------------------------------------------ webhooks

it('accepts a webhook whose HMAC-SHA256 signature matches the raw body', function () {
    $rawBody = '{"event":"video.publish.complete","id":"evt-1"}';
    $signature = hash_hmac('sha256', $rawBody, 'tt-webhook-secret');

    $verified = tiktokProvider()->verifyWebhook(new VerifiedWebhookRequest(
        provider: 'tiktok',
        headers: ['TikTok-Webhook-Signature' => $signature],
        payload: ['event' => 'video.publish.complete'],
        rawBody: $rawBody,
    ));

    expect($verified)->toBeTrue();
});

it('rejects a webhook with a bad signature', function () {
    try {
        tiktokProvider()->verifyWebhook(new VerifiedWebhookRequest(
            provider: 'tiktok',
            headers: ['TikTok-Webhook-Signature' => str_repeat('0', 64)],
            payload: [],
            rawBody: '{"event":"x"}',
        ));
        $this->fail('Expected a WebhookSignatureException.');
    } catch (WebhookSignatureException $e) {
        expect($e->provider)->toBe('tiktok');
    }
});

it('rejects a webhook with no signature header at all', function () {
    tiktokProvider()->verifyWebhook(new VerifiedWebhookRequest(
        provider: 'tiktok',
        headers: [],
        payload: [],
        rawBody: '{"event":"x"}',
    ));
})->throws(WebhookSignatureException::class);

it('normalises a TikTok webhook body', function () {
    $normalized = tiktokProvider()->normalizeWebhook([
        'event' => 'video.publish.complete',
        'id' => 'evt-1',
        'event_time' => 1758000000,
        'data' => ['id' => 'vid-1'],
    ]);

    expect($normalized['provider'])->toBe('tiktok')
        ->and($normalized['event_type'])->toBe('video.publish.complete')
        ->and($normalized['changes'][0]['object_id'])->toBe('vid-1');
});

// -------------------------------------------------------------------- errors

it('turns a 429 into a rate limit exception with the real delay', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/video/init/*' => Http::response([
        'error' => ['code' => 429, 'message' => 'Rate limit exceeded'],
    ], 429, ['Retry-After' => '120'])]);

    try {
        tiktokProvider()->createPost(tiktokAccount(), [
            'text' => 'throttled',
            'media_ids' => ['https://media.example.com/clip.mp4'],
        ]);
        $this->fail('Expected a RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(120)
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

it('treats a 401 as a dead access token before the provider error map runs', function () {
    Http::fake(['https://open.tiktokapis.com/v2/user/info/*' => Http::response([
        'error' => ['code' => 'access_token_invalid', 'message' => 'Invalid access token'],
    ], 401)]);

    tiktokProvider()->getAccount(tiktokAccount());
})->throws(\App\Exceptions\Social\TokenExpiredException::class);

it('maps an invalid access token reported with a 400 to a reconnect error', function () {
    Http::fake(['https://open.tiktokapis.com/v2/user/info/*' => Http::response([
        'error' => ['code' => 'access_token_invalid', 'message' => 'Invalid access token'],
    ], 400)]);

    try {
        tiktokProvider()->getAccount(tiktokAccount());
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('token_invalid')
            ->and($e->userFacingError->remediation)->toContain('Reconnect');
    }
});

it('maps a missing consent grant to a consent error', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/video/init/*' => Http::response([
        'error' => ['code' => 'consent_required', 'message' => 'Consent has not been granted'],
    ], 400)]);

    try {
        tiktokProvider()->createPost(tiktokAccount(), [
            'text' => 'needs consent',
            'media_ids' => ['https://media.example.com/clip.mp4'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('consent_required');
    }
});

it('maps the daily post cap to a retryable error', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/video/init/*' => Http::response([
        'error' => ['code' => 'spam_risk_too_many_posts', 'message' => 'Daily post cap reached'],
    ], 400)]);

    try {
        tiktokProvider()->createPost(tiktokAccount(), [
            'text' => 'too many',
            'media_ids' => ['https://media.example.com/clip.mp4'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('daily_post_cap')
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

it('maps an unverified media domain to a domain verification error', function () {
    Http::fake(['https://open.tiktokapis.com/v2/post/publish/video/init/*' => Http::response([
        'error' => ['code' => 'url_ownership_unverified', 'message' => 'Domain not verified'],
    ], 400)]);

    try {
        tiktokProvider()->createPost(tiktokAccount(), [
            'text' => 'bad domain',
            'media_ids' => ['https://media.example.com/clip.mp4'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('url_verification_required');
    }
});

it('never leaks the access token in an error or log line', function () {
    Http::fake(['https://open.tiktokapis.com/v2/user/info/*' => Http::response([
        'error' => ['code' => 'invalid_params', 'message' => 'bad request'],
    ], 400)]);

    try {
        tiktokProvider()->getAccount(tiktokAccount());
        $this->fail('Expected a ProviderApiException.');
    } catch (\Throwable $e) {
        $serialised = json_encode($e->userFacingError->toArray()).json_encode($e->context ?? []);

        expect($serialised)->not->toContain('tt-access-token')
            ->and($serialised)->not->toContain('tt-client-secret');
    }
});

// ---------------------------------------------------------------------- auth

it('builds a consent redirect that carries the state the callback expects', function () {
    $url = tiktokProvider()->consentUrl(new AuthRequest(provider: 'tiktok', state: 'state-1'));

    expect($url)->toContain('state=state-1')
        ->and($url)->toStartWith('https://www.tiktok.com/consent/');
});

it('reports missing credentials instead of pretending to connect', function () {
    config()->set('socialhub.providers.tiktok.credentials', [
        'client_key' => null,
        'client_secret' => null,
    ]);

    tiktokProvider()->getAccount(tiktokAccount());
})->throws(ProviderNotConfiguredException::class, 'TIKTOK_CLIENT_KEY');
