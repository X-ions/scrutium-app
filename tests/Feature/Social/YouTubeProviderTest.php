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
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\ProviderHttpClient;
use App\Services\Social\ProviderRateLimiter;
use App\Services\Social\Providers\YouTubeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Every outbound call is faked, so this suite asserts the exact YouTube Data
 * API and Analytics API endpoints without ever touching the network.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('socialhub.providers.youtube.credentials', [
        'client_id' => 'yt-client-id',
        'client_secret' => 'yt-client-secret',
    ]);

    config()->set('socialhub.providers.youtube.oauth.client_id', 'yt-client-id');
    config()->set('socialhub.providers.youtube.oauth.client_secret', 'yt-client-secret');
    config()->set('socialhub.providers.youtube.oauth.access_type', 'offline');

    Http::preventStrayRequests();
});

function youtubeProvider(): YouTubeProvider
{
    return new YouTubeProvider(
        http: new ProviderHttpClient(
            limiter: new ProviderRateLimiter(redis: null),
            provider: 'youtube',
        ),
    );
}

function youtubeAccount(string $channelId = 'UCchannel123'): SocialAccount
{
    $tenant = Tenant::first() ?? Tenant::create(['name' => 'SocialHub Test', 'slug' => 'socialhub-test-'.uniqid()]);

    $account = new SocialAccount([
        'tenant_id' => $tenant->id,
        'provider' => 'youtube',
        'provider_account_id' => $channelId.''.uniqid(),
        'provider_display_name' => 'Test Channel',
        'account_type' => 'channel',
        'status' => 'connected',
        'metadata' => ['granted_scopes' => [
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.readonly',
        ]],
    ]);

    $account->save();

    $token = new SocialAccountToken([
        'social_account_id' => $account->id,
        'access_token' => 'yt-access-token',
        'refresh_token' => 'yt-refresh-token',
        'token_type' => 'Bearer',
    ]);

    $token->save();
    $account->setRelation('token', $token);

    return $account;
}

function youtubeMedia(string $path = 'videos/clip.mp4', int $bytes = 2048): MediaAsset
{
    Storage::fake('local');

    // The oversized case is asserted on the declared file_size only, so the
    // file on disk stays small and the test does not allocate gigabytes.
    Storage::disk('local')->put($path, str_repeat('a', min($bytes, 4096)));

    $media = new MediaAsset([
        'tenant_id' => Tenant::firstOrFail()->id,
        'user_id' => \App\Models\User::factory()->create()->id,
        'filename' => 'clip.mp4',
        'stored_filename' => 'clip.mp4',
        'mime_type' => 'video/mp4',
        'file_size' => $bytes,
        'storage_disk' => 'local',
        'storage_path' => $path,
    ]);

    $media->save();

    return $media;
}

function youtubeAnalytics(array $postIds = [], array $metrics = []): AnalyticsQuery
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

it('declares video-only publishing and no signed webhooks', function () {
    $capabilities = youtubeProvider()->getSupportedFeatures();

    expect($capabilities->publishing)->toBeTrue()
        ->and($capabilities->videoPublishing)->toBeTrue()
        // Shorts are ordinary videos.insert uploads.
        ->and($capabilities->shorts)->toBeTrue()
        // YouTube has no image post, carousel, story, or text post.
        ->and($capabilities->imagePublishing)->toBeFalse()
        ->and($capabilities->carouselPublishing)->toBeFalse()
        ->and($capabilities->textPublishing)->toBeFalse()
        // Pub/SubHubbub carries no signature, so there is nothing to verify.
        ->and($capabilities->webhooks)->toBeFalse()
        ->and($capabilities->scheduling)->toBeTrue()
        ->and($capabilities->deletePost)->toBeTrue();
});

it('refuses a publish with no video because YouTube publishes video only', function () {
    try {
        youtubeProvider()->createPost(youtubeAccount(), ['text' => 'no video here']);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('videoPublishing')
            ->and($e->userFacingError->userMessage)->toContain('Upload the video first');
    }
});

it('refuses a webhook because YouTube sends no signature to verify', function () {
    $request = new VerifiedWebhookRequest(
        provider: 'youtube',
        headers: ['X-Hub-Signature-256' => 'sha256=anything'],
        payload: ['kind' => 'youtube#video'],
        rawBody: '{}',
    );

    try {
        youtubeProvider()->verifyWebhook($request);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('webhooks')
            ->and($e->userFacingError->userMessage)->toContain('does not deliver signed webhooks')
            ->and($e->userFacingError->remediation)->toContain('scheduled analytics sync');
    }
});

it('still normalises a notification body for completeness', function () {
    $normalized = youtubeProvider()->normalizeWebhook([
        'kind' => 'youtube#video',
        'id' => 'evt-1',
        'data' => ['id' => 'vid-1'],
    ]);

    expect($normalized['provider'])->toBe('youtube')
        ->and($normalized['event_id'])->toBe('evt-1')
        ->and($normalized['changes'][0]['object_id'])->toBe('vid-1');
});

// ----------------------------------------------------------------- uploading

it('runs the resumable upload protocol and returns the video id', function () {
    $calls = [];

    Http::fake(function ($request) use (&$calls) {
        $calls[] = $request->method().' '.$request->url();

        return match (true) {
            str_contains($request->url(), 'uploadType=resumable') => Http::response(['id' => 'vid-new'], 200, [
                'Location' => 'https://www.googleapis.com/upload/session/abc',
            ]),
            str_ends_with($request->url(), '/session/abc') => Http::response('', 200),
            str_contains($request->url(), '/videos?part=processingDetails') => Http::response([
                'items' => [['id' => 'vid-new', 'processingDetails' => ['processingStatus' => 'succeeded']]],
            ], 200),
            default => Http::response([], 404),
        };
    });

    $mediaId = youtubeProvider()->uploadMedia(youtubeAccount(), youtubeMedia());

    expect($mediaId)->toBe('vid-new')
        ->and($calls[0])->toContain('POST https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable')
        ->and($calls[1])->toContain('PUT https://www.googleapis.com/upload/session/abc')
        ->and($calls[2])->toContain('/youtube/v3/videos');

    // The byte transfer is a real body, not a stub.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/session/abc')
        && $request->body() === str_repeat('a', 2048));
});

it('reports a failed processing status rather than returning an id that will never publish', function () {
    Http::fake([
        'https://www.googleapis.com/upload/youtube/v3/videos?*' => Http::response(['id' => 'vid-bad'], 200, [
            'Location' => 'https://www.googleapis.com/upload/session/abc',
        ]),
        'https://www.googleapis.com/upload/session/abc' => Http::response('', 200),
        'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
            'items' => [['id' => 'vid-bad', 'processingDetails' => [
                'processingStatus' => 'failed',
                'processingFailureReason' => 'invalidFile',
            ]]],
        ], 200),
    ]);

    try {
        youtubeProvider()->uploadMedia(youtubeAccount(), youtubeMedia());
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('upload_failed')
            ->and($e->userFacingError->retryable)->toBeFalse();
    }
});

it('refuses a file larger than the platform limit before uploading it', function () {
    Http::fake();

    try {
        youtubeProvider()->uploadMedia(youtubeAccount(), youtubeMedia(bytes: 3_000_000_000));
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('media_too_large');
    }

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- publishing

it('publishes immediately with a private default privacy status', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
            'id' => 'vid-1',
            'snippet' => ['title' => 'My video', 'description' => 'A description'],
            'status' => ['privacyStatus' => 'private', 'publishAt' => '2026-09-29T10:00:00Z'],
        ], 200),
    ]);

    $post = youtubeProvider()->createPost(youtubeAccount(), [
        'title' => 'My video',
        'text' => 'A description',
        'media_ids' => ['vid-1'],
    ]);

    expect($post->providerPostId)->toBe('vid-1')
        ->and($post->permalink)->toBe('https://www.youtube.com/watch?v=vid-1');

    Http::assertSent(function ($request) {
        $body = $request->data();

        // The metadata write targets the Data API, never the upload endpoint.
        return str_contains($request->url(), '/upload/youtube/v3/videos') === false
            && str_contains($request->url(), '/youtube/v3/videos')
            && $body['status']['privacyStatus'] === 'private'
            && ! isset($body['status']['publishAt']);
    });
});

it('schedules through status.publishAt with a private privacy status', function () {
    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response(['id' => 'vid-2'], 200)]);

    youtubeProvider()->createPost(youtubeAccount(), [
        'title' => 'Later',
        'text' => 'Scheduled body',
        'media_ids' => ['vid-2'],
        'scheduled_at' => (new DateTimeImmutable('+2 days'))->format(DATE_ATOM),
    ]);

    Http::assertSent(function ($request) {
        $status = ($request->data())['status'] ?? [];

        // A scheduled upload must be private or YouTube rejects the request.
        return $status['privacyStatus'] === 'private' && isset($status['publishAt']);
    });
});

it('refuses a schedule inside the fifteen minute lead time', function () {
    Http::fake();

    try {
        youtubeProvider()->createPost(youtubeAccount(), [
            'title' => 'Too soon',
            'text' => 'body',
            'media_ids' => ['vid-3'],
            'scheduled_at' => (new DateTimeImmutable('+5 minutes'))->format(DATE_ATOM),
        ]);
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('scheduling')
            ->and($e->userFacingError->userMessage)->toContain('at least 15 minutes');
    }

    Http::assertNothingSent();
});

it('reads comment threads and replies through comments.insert with a parentId', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/commentThreads*' => Http::response([
            'items' => [[
                'id' => 'thread-1',
                'snippet' => [
                    'topLevelComment' => [
                        'id' => 'c1',
                        'snippet' => [
                            'textOriginal' => 'Great video',
                            'authorChannelId' => ['value' => 'UCauthor'],
                            'likeCount' => 12,
                            'publishedAt' => '2026-09-20T10:00:00Z',
                            'totalReplyCount' => 2,
                        ],
                    ],
                ],
            ]],
            'nextPageToken' => null,
        ], 200),
        'https://www.googleapis.com/youtube/v3/comments*' => Http::response(['id' => 'r1'], 200),
    ]);

    $comments = youtubeProvider()->getComments(youtubeAccount(), 'vid-1');

    expect($comments)->toHaveCount(1)
        ->and($comments[0]->providerCommentId)->toBe('c1')
        ->and($comments[0]->content)->toBe('Great video')
        ->and($comments[0]->likeCount)->toBe(12)
        ->and($comments[0]->replyCount)->toBe(2);

    youtubeProvider()->replyToComment(youtubeAccount(), $comments[0], 'thanks!');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/youtube/v3/comments')
        && $request['body']['snippet']['parentId'] === 'c1'
        && $request['body']['snippet']['textOriginal'] === 'thanks!');
});

it('reads lifetime counters from statistics and watch time from the reports API', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/videos?*part=statistics*' => Http::response([
            'items' => [['id' => 'vid-1', 'statistics' => ['viewCount' => '5000', 'likeCount' => '120', 'commentCount' => '8']]],
        ], 200),
        'https://youtubeanalytics.googleapis.com/v2/reports*' => Http::response([
            'rows' => [['2026-09-20', 42.5, 155.0]],
        ], 200),
    ]);

    $batch = youtubeProvider()->getAnalytics(youtubeAccount(), youtubeAnalytics(['vid-1']));

    $postMetrics = collect($batch->postMetrics['vid-1'])->keyBy('metric');

    expect($postMetrics['views']->value)->toBe(5000.0)
        ->and($postMetrics['likes']->value)->toBe(120.0)
        ->and($postMetrics['comments']->value)->toBe(8.0);

    $accountMetrics = collect($batch->accountMetrics)->keyBy('metric');

    expect($accountMetrics['watch_time_minutes']->value)->toBe(42.5)
        ->and($accountMetrics['avg_view_duration_seconds']->value)->toBe(155.0);

    // The Analytics API is a genuinely different host and is really called.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'youtubeanalytics.googleapis.com/v2/reports')
        && str_contains($request->url(), 'watch_time_minutes=estimatedMinutesWatched'.urlencode('')) === false
        && str_contains($request['metrics'] ?? (string) $request->url(), 'estimatedMinutesWatched'));
});

it('reads the channel and its subscriber count from channels.list', function () {
    Http::fake(['https://www.googleapis.com/youtube/v3/channels*' => Http::response([
        'items' => [[
            'id' => 'UCchannel123',
            'snippet' => ['title' => 'Test Channel', 'customUrl' => '@testchannel'],
            'statistics' => ['subscriberCount' => '12345'],
        ]],
    ], 200)]);

    $account = youtubeAccount('UCchannel123');

    $profile = youtubeProvider()->getAccount($account);

    expect($profile->displayName)->toBe('Test Channel')
        ->and($profile->username)->toBe('@testchannel')
        ->and($profile->followerCount)->toBe(12345);

    expect(youtubeProvider()->getFollowers($account)->total)->toBe(12345);
});

it('deletes a video through videos.delete', function () {
    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response('', 200)]);

    expect(youtubeProvider()->deletePost(youtubeAccount(), 'vid-1'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'id=vid-1'));
});

// -------------------------------------------------------------------- errors

it('turns a 429 into a rate limit exception with the real delay', function () {
    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response([
        'error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota'],
    ], 429, ['Retry-After' => '300'])]);

    try {
        youtubeProvider()->createPost(youtubeAccount(), [
            'title' => 'Throttled',
            'text' => 'body',
            'media_ids' => ['vid-1'],
        ]);
        $this->fail('Expected a RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(300)
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

it('maps the daily upload cap to a retryable error', function () {
    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response([
        'error' => [
            'status' => 'dailyLimitExceeded',
            'message' => 'The request cannot be completed because you have exceeded your daily upload quota.',
        ],
    ], 403)]);

    try {
        youtubeProvider()->createPost(youtubeAccount(), [
            'title' => 'Over quota',
            'text' => 'body',
            'media_ids' => ['vid-1'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('quota_exceeded')
            ->and($e->userFacingError->retryable)->toBeTrue();
    }
});

it('maps a disabled API to an enable-it-in-the-console remediation', function () {
    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response([
        'error' => ['status' => 'accessNotConfigured', 'message' => 'Access Not Configured'],
    ], 403)]);

    try {
        youtubeProvider()->createPost(youtubeAccount(), [
            'title' => 'Disabled',
            'text' => 'body',
            'media_ids' => ['vid-1'],
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('api_not_enabled')
            ->and($e->userFacingError->remediation)->toContain('YouTube Data API v3');
    }
});

it('maps a rejected publish time to a schedule error', function () {
    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response([
        'error' => ['status' => 'invalidPublishAt', 'message' => 'Invalid publishAt'],
    ], 400)]);

    try {
        youtubeProvider()->createPost(youtubeAccount(), [
            'title' => 'Bad time',
            'text' => 'body',
            'media_ids' => ['vid-1'],
            'scheduled_at' => (new DateTimeImmutable('+3 days'))->format(DATE_ATOM),
        ]);
        $this->fail('Expected a ProviderApiException.');
    } catch (ProviderApiException $e) {
        expect($e->userFacingError->code)->toBe('invalid_schedule')
            ->and($e->userFacingError->remediation)->toContain('15 minutes');
    }
});

it('treats a 401 as an expired token', function () {
    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response([
        'error' => ['status' => 'unauthorized', 'message' => 'Invalid Credentials'],
    ], 401)]);

    youtubeProvider()->createPost(youtubeAccount(), [
        'title' => 'Expired',
        'text' => 'body',
        'media_ids' => ['vid-1'],
    ]);
})->throws(\App\Exceptions\Social\AuthenticationException::class);

// ---------------------------------------------------------------------- auth

it('asks Google for offline access so a refresh token is ever issued', function () {
    $session = youtubeProvider()->authenticate(new AuthRequest(
        provider: 'youtube',
        state: 'state-1',
        redirectUri: 'https://app.test/callback',
    ));

    expect($session->authorizationUrl)->toContain('accounts.google.com')
        ->and($session->authorizationUrl)->toContain('access_type=offline')
        ->and($session->authorizationUrl)->toContain('youtube.upload')
        ->and($session->authorizationUrl)->toContain('youtube.force-ssl');
});

it('reports missing credentials instead of pretending to connect', function () {
    config()->set('socialhub.providers.youtube.credentials', [
        'client_id' => null,
        'client_secret' => null,
    ]);

    youtubeProvider()->getAccount(youtubeAccount());
})->throws(ProviderNotConfiguredException::class, 'YOUTUBE_CLIENT_ID');

it('treats a connection with no refresh token as unrecoverable', function () {
    $account = youtubeAccount();
    $account->token->update(['refresh_token' => null]);
    $account->setRelation('token', $account->token->fresh());

    youtubeProvider()->refreshToken($account);
})->throws(TokenRevokedException::class);
