<?php

declare(strict_types=1);

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Jobs\Webhook\ProcessWebhookJob;
use App\Models\Comment;
use App\Models\SocialHubNotification;
use App\Models\WebhookEvent;
use App\Services\Engagement\WebhookIngestService;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Engagement\FakeEngagementProvider;

require_once __DIR__.'/../../Support/engagement_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::forget();
    useFakeEngagementProviders();
    config()->set('socialhub.webhooks.enabled', true);
});

afterEach(fn () => TenantContext::forget());

function ingest(): WebhookIngestService
{
    return app(WebhookIngestService::class);
}

/**
 * A signed, correctly shaped Facebook-style delivery.
 *
 * @param  array<string, mixed>  $overrides
 */
function signedWebhook(array $overrides = [], string $provider = 'facebook', ?string $secret = null): VerifiedWebhookRequest
{
    $payload = $overrides + [
        'object' => 'page',
        'event_id' => 'evt-'.uniqid(),
        'entry' => [[
            'id' => 'page-1',
            'changes' => [[
                'field' => 'feed',
                'value' => [
                    'comment_id' => 'c-1',
                    'post_id' => 'post-1',
                    'verb' => 'add',
                    'message' => 'Love this campaign',
                    'from' => ['id' => 'u-1', 'name' => 'Alex'],
                    'created_time' => time() - 60,
                ],
            ]],
        ]],
    ];

    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $secret ??= FakeEngagementProvider::SECRET;

    return new VerifiedWebhookRequest(
        provider: $provider,
        rawBody: $raw,
        payload: $payload,
        headers: [
            'x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
            'x-hub-signature-timestamp' => (string) time(),
        ],
        receivedAt: time(),
    );
}

// 10. Invalid signature

it('rejects a delivery whose signature does not match the body', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    publishedVariant($tenant, $account, 'post-1');

    $request = new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: json_encode(['event_id' => 'evt-1']),
        payload: ['event_id' => 'evt-1'],
        headers: ['x-hub-signature-256' => 'sha256='.str_repeat('0', 64)],
        receivedAt: time(),
    );

    $result = ingest()->ingest($request);

    expect($result->outcome)->toBe('refused')
        ->and($result->reason)->toBe('invalid_signature')
        ->and($result->httpStatus())->toBe(401)
        // Nothing was recorded and nothing was applied.
        ->and(WebhookEvent::query()->count())->toBe(0)
        ->and(Comment::query()->count())->toBe(0);
});

it('rejects a delivery signed with the wrong secret', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    publishedVariant($tenant, $account, 'post-1');

    $result = ingest()->ingest(signedWebhook([], 'facebook', 'not-the-configured-secret'));

    expect($result->reason)->toBe('invalid_signature')
        ->and(WebhookEvent::query()->count())->toBe(0)
        ->and(Comment::query()->count())->toBe(0);
});

it('rejects a delivery with no signature at all', function (): void {
    $result = ingest()->ingest(new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: json_encode(['event_id' => 'evt-1']),
        payload: ['event_id' => 'evt-1'],
        headers: [],
        receivedAt: time(),
    ));

    expect($result->reason)->toBe('invalid_signature')
        ->and(WebhookEvent::query()->count())->toBe(0);
});

it('rejects a delivery that is not valid json', function (): void {
    $result = ingest()->ingest(new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: 'not json at all',
        payload: [],
        headers: ['x-hub-signature-256' => 'sha256=x'],
        receivedAt: time(),
    ));

    expect($result->reason)->toBe('invalid_signature');
});

// 11. Stale timestamp

it('rejects a delivery whose timestamp is outside the tolerance window', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    // A fresh, correctly signed delivery inside the window is accepted.
    expect(ingest()->ingest(signedWebhook())->outcome)->toBe('processed');

    // The same shape, signed correctly but timestamped an hour ago, is refused.
    $stale = signedWebhook();
    $headers = $stale->headers;
    $headers['x-hub-signature-timestamp'] = (string) (time() - 3600);

    $staleResult = ingest()->ingest(new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: $stale->rawBody,
        payload: $stale->payload,
        headers: $headers,
        receivedAt: time(),
    ));

    expect($staleResult->outcome)->toBe('refused')
        ->and($staleResult->reason)->toBe('stale_timestamp')
        ->and($staleResult->httpStatus())->toBe(400)
        // The refused delivery was never recorded, so it never becomes work owed.
        ->and(WebhookEvent::query()->count())->toBe(1)
        ->and(Comment::query()->count())->toBe(1);
});

it('accepts a delivery inside the tolerance window', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    $request = signedWebhook();
    $headers = $request->headers;
    $headers['x-hub-signature-timestamp'] = (string) (time() - 60);

    $result = ingest()->ingest(new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: $request->rawBody,
        payload: $request->payload,
        headers: $headers,
        receivedAt: time(),
    ));

    expect($result->outcome)->toBe('processed');
});

// 9. Idempotency

it('processes a duplicate event id exactly once', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    $variant = publishedVariant($tenant, $account, 'post-1');

    $request = signedWebhook(['event_id' => 'evt-duplicate']);

    $first = ingest()->ingest($request);
    $second = ingest()->ingest($request);
    $third = ingest()->ingest($request);

    expect($first->outcome)->toBe('processed')
        ->and($first->wasApplied())->toBeTrue()
        ->and($second->outcome)->toBe('duplicate')
        ->and($third->outcome)->toBe('duplicate');

    // The side effect happened once: one event row, one comment, one notification.
    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(Comment::query()->count())->toBe(1)
        ->and(SocialHubNotification::query()->where('type', 'comment.new')->count())->toBe(1);

    $comment = Comment::query()->first();

    expect($comment->provider_comment_id)->toBe('c-1')
        ->and($comment->content)->toBe('Love this campaign')
        ->and($comment->author_display_name)->toBe('Alex');
});

it('returns immediately for a duplicate without reprocessing the payload', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    $request = signedWebhook(['event_id' => 'evt-ack']);

    ingest()->ingest($request);

    $event = WebhookEvent::query()->first();
    $event->forceFill(['processed' => false])->save();

    $second = ingest()->ingest($request);

    // Still exactly one event and one comment: the duplicate short-circuits
    // before the handler runs, so a corrupted `processed` flag cannot cause a
    // second application.
    expect($second->outcome)->toBe('duplicate')
        ->and(WebhookEvent::query()->count())->toBe(1)
        ->and(Comment::query()->count())->toBe(1)
        ->and($second->shouldRespondOk())->toBeTrue();
});

it('deduplicates by event id, not by payload', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    ingest()->ingest(signedWebhook(['event_id' => 'evt-same-id']));

    // A different body reusing the same id is still a duplicate: the provider
    // said this is event N, and N has been handled.
    $second = ingest()->ingest(signedWebhook([
        'event_id' => 'evt-same-id',
        'entry' => [[
            'id' => 'page-1',
            'changes' => [[
                'field' => 'feed',
                'value' => ['comment_id' => 'c-different', 'post_id' => 'post-1', 'message' => 'Different'],
            ]],
        ]],
    ]));

    expect($second->outcome)->toBe('duplicate')
        ->and(Comment::query()->count())->toBe(1)
        ->and(Comment::query()->where('provider_comment_id', 'c-different')->exists())->toBeFalse();
});

it('deduplicates two distinct events that carry the same comment', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    ingest()->ingest(signedWebhook(['event_id' => 'evt-a']));
    $second = ingest()->ingest(signedWebhook(['event_id' => 'evt-b']));

    expect($second->outcome)->toBe('processed')
        // Two events, but the comment is still one row: the unique key on
        // (provider, provider_comment_id) is the second line of defence.
        ->and(WebhookEvent::query()->count())->toBe(2)
        ->and(Comment::query()->count())->toBe(1)
        ->and(SocialHubNotification::query()->where('type', 'comment.new')->count())->toBe(1);
});

it('is idempotent under a redelivery with a different signature header', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    $request = signedWebhook(['event_id' => 'evt-hdr']);

    ingest()->ingest($request);

    $resigned = new VerifiedWebhookRequest(
        provider: $request->provider,
        rawBody: $request->rawBody,
        payload: $request->payload,
        headers: $request->headers + ['x-request-id' => 'a-different-proxy-id'],
        receivedAt: time(),
    );

    expect(ingest()->ingest($resigned)->outcome)->toBe('duplicate')
        ->and(Comment::query()->count())->toBe(1);
});

it('does not reprocess an already processed event when the job runs twice', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    $request = signedWebhook(['event_id' => 'evt-job']);
    $result = ingest()->ingest($request);

    $event = WebhookEvent::query()->find($result->webhookEventId);
    $event->forceFill(['processed' => false])->save();

    $job = new ProcessWebhookJob((int) $event->getKey(), (int) $tenant->getKey());
    $job->handle(ingest());
    $job->handle(ingest());

    expect(Comment::query()->count())->toBe(1);
});

// Account events

it('marks an account revoked on a permission withdrawal and notifies the workspace once', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);

    $payload = [
        'object' => 'page',
        'event_id' => 'evt-revoke',
        'entry' => [[
            'id' => 'page-1',
            'changes' => [['field' => 'permissions', 'value' => ['verb' => 'revoke']]],
        ]],
    ];

    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    $request = new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: $raw,
        payload: $payload,
        headers: ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $raw, FakeEngagementProvider::SECRET)],
        receivedAt: time(),
    );

    ingest()->ingest($request);
    ingest()->ingest($request);

    expect($account->refresh()->status)->toBe(SocialAccountStatus::Revoked)
        ->and(SocialHubNotification::query()->where('type', 'social_account.token_revoked')->count())->toBe(1)
        ->and(WebhookEvent::query()->count())->toBe(1);
});

// Unknown event types

it('records an unknown event type as processed instead of error looping', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);

    $payload = [
        'object' => 'page',
        'event_id' => 'evt-unknown',
        'entry' => [['id' => 'page-1', 'changes' => [['field' => 'some_new_thing', 'value' => ['x' => 1]]]]],
    ];

    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    $result = ingest()->ingest(new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: $raw,
        payload: $payload,
        headers: ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $raw, FakeEngagementProvider::SECRET)],
        receivedAt: time(),
    ));

    expect($result->outcome)->toBe('processed')
        ->and(WebhookEvent::query()->first()->processed)->toBeTrue()
        ->and(WebhookEvent::query()->first()->error_message)->toBeNull()
        ->and(SocialHubNotification::query()->where('type', 'webhook.processing_failed')->count())->toBe(0);
});

// Unmatched and failing

it('records an event for an account it does not know without applying it', function (): void {
    $request = signedWebhook(['event_id' => 'evt-unknown-page']);

    $result = ingest()->ingest($request);

    expect($result->outcome)->toBe('unmatched')
        ->and($result->shouldRespondOk())->toBeTrue()
        ->and(Comment::query()->count())->toBe(0);
});

it('does not apply a comment to a post from a different account', function (): void {
    $tenant = engagementTenant();

    $accountA = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    $accountB = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-2']);

    $variantA = publishedVariant($tenant, $accountA, 'post-1');
    publishedVariant($tenant, $accountB, 'post-1');

    ingest()->ingest(signedWebhook(['event_id' => 'evt-cross']));

    expect(Comment::query()->count())->toBe(1)
        ->and((int) Comment::query()->first()->post_variant_id)->toBe((int) $variantA->getKey());
});

it('records a handler failure on the event and raises one notification', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    // A payload that normalises to a comment event but cannot be applied.
    $payload = [
        'object' => 'page',
        'event_id' => 'evt-broken',
        'entry' => [['id' => 'page-1', 'changes' => [['field' => 'feed', 'value' => ['verb' => 'add']]]]],
    ];

    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    $result = ingest()->ingest(new VerifiedWebhookRequest(
        provider: 'facebook',
        rawBody: $raw,
        payload: $payload,
        headers: ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $raw, FakeEngagementProvider::SECRET)],
        receivedAt: time(),
    ));

    // A comment event with no post id is simply not attributable, which is not
    // a failure: nothing was corrupted, so the event is marked processed.
    expect($result->outcome)->toBe('processed')
        ->and(Comment::query()->count())->toBe(0)
        ->and(WebhookEvent::query()->first()->processed)->toBeTrue();
});

it('keeps the raw payload on the event row so an operator can inspect it', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    $request = signedWebhook(['event_id' => 'evt-inspect']);
    ingest()->ingest($request);

    $event = WebhookEvent::query()->first();

    expect($event->payload['event_id'])->toBe('evt-inspect')
        ->and($event->event_type)->toBe('feed')
        ->and($event->provider)->toBe(SocialPlatform::Facebook)
        ->and($event->tenant_id)->toBe($tenant->getKey());
});

it('never stores a credential in the webhook event row', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);
    publishedVariant($tenant, $account, 'post-1');

    $account->token()->create([
        'social_account_id' => $account->getKey(),
        'access_token' => 'super-secret-access-token',
        'refresh_token' => 'super-secret-refresh-token',
        'expires_at' => now()->addDays(30),
    ]);

    ingest()->ingest(signedWebhook(['event_id' => 'evt-secret']));

    $encoded = json_encode(WebhookEvent::query()->first()->toArray());

    expect($encoded)->not->toContain('super-secret-access-token');
});

it('refuses a delivery for a provider it cannot resolve', function (): void {
    $result = ingest()->ingest(new VerifiedWebhookRequest(
        provider: 'tiktok',
        rawBody: json_encode(['event_id' => 'x']),
        payload: ['event_id' => 'x'],
        headers: [],
        receivedAt: time(),
    ));

    expect($result->outcome)->toBe('refused');
});
