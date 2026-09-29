<?php

declare(strict_types=1);

use App\Enums\CommentSyncStatus;
use App\Enums\SocialPlatform;
use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Jobs\Engagement\SendCommentReplyJob;
use App\Models\CommentReply;
use App\Models\SocialHubNotification;
use App\Services\Engagement\ReplyService;
use App\Services\Social\Data\UserFacingError;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Engagement\FakeEngagementProvider;

require_once __DIR__.'/../../Support/engagement_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::forget();
    useFakeEngagementProviders();
});

afterEach(fn () => TenantContext::forget());

function replies(): ReplyService
{
    return app(ReplyService::class);
}

// 7. Unsupported capability

it('throws a friendly unsupported-capability error for a platform that cannot reply', function (): void {
    $tenant = engagementTenant();

    // Pinterest is the one network in the verified matrix with no comment API.
    $account = engagementAccount($tenant, SocialPlatform::Pinterest);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    try {
        replies()->reply($comment, $user, 'Thanks for the feedback!');
        $this->fail('Pinterest has no comment API, so the reply should have been refused.');
    } catch (UnsupportedCapabilityException $e) {
        $message = $e->userFacingError?->userMessage ?? $e->getMessage();

        expect($e->capability)->toBe('commentReplies')
            ->and($e->provider)->toBe('pinterest')
            ->and($message)->toContain('Replying is not available on Pinterest')
            ->and($e->userFacingError?->remediation)->not->toBeNull();
    }
});

it('writes nothing and queues nothing when a platform cannot reply', function (): void {
    Queue::fake();

    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Pinterest);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    try {
        replies()->reply($comment, $user, 'Thanks!');
    } catch (UnsupportedCapabilityException) {
        // expected
    }

    expect(CommentReply::query()->count())->toBe(0)
        ->and(Queue::assertNothingPushed());
});

it('tells the ui whether a reply box should be shown, and why not', function (): void {
    $tenant = engagementTenant();

    $pinterest = engagementAccount($tenant, SocialPlatform::Pinterest);
    $facebook = engagementAccount($tenant, SocialPlatform::Facebook);

    expect(replies()->canReply(commentOn($tenant, $pinterest)))->toBeFalse()
        ->and(replies()->refusalReason(commentOn($tenant, $pinterest)))
        ->toContain('Pinterest')
        ->and(replies()->canReply(commentOn($tenant, $facebook)))->toBeTrue()
        ->and(replies()->refusalReason(commentOn($tenant, $facebook)))->toBeNull();
});

// 8. Replies are queued, never inline

it('dispatches a queued job instead of calling the provider inline', function (): void {
    Queue::fake();

    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    $reply = replies()->reply($comment, $user, 'Thanks for the feedback!');

    // The provider was never touched during the request.
    expect(FakeEngagementProvider::$repliedTo)->toBe([])
        ->and($reply->statusEnum())->toBe(CommentSyncStatus::Pending)
        ->and($reply->content)->toBe('Thanks for the feedback!')
        ->and($reply->sent_at)->toBeNull();

    Queue::assertPushed(SendCommentReplyJob::class, function (SendCommentReplyJob $job) use ($reply, $tenant): bool {
        return $job->commentReplyId === (int) $reply->getKey()
            && $job->tenantId === (int) $tenant->getKey();
    });
});

it('delivers the reply when the queued job runs', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    $reply = replies()->reply($comment, $user, 'On it');

    (new SendCommentReplyJob((int) $reply->getKey(), (int) $tenant->getKey()))->handle(
        app(\App\Services\Social\SocialProviderRegistry::class),
        replies(),
        app(\App\Services\Engagement\NotificationService::class),
    );

    $reply->refresh();

    expect($reply->statusEnum())->toBe(CommentSyncStatus::Sent)
        ->and($reply->sent_at)->not->toBeNull()
        ->and(FakeEngagementProvider::$repliedTo)->toBe([(int) $account->getKey()])
        ->and(FakeEngagementProvider::$replyBodies)->toBe(['On it']);
});

it('never sends the same reply twice if the job runs again', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    $reply = replies()->reply($comment, $user, 'On it');
    $job = new SendCommentReplyJob((int) $reply->getKey(), (int) $tenant->getKey());

    $dependencies = [
        app(\App\Services\Social\SocialProviderRegistry::class),
        replies(),
        app(\App\Services\Engagement\NotificationService::class),
    ];

    $job->handle(...$dependencies);
    $job->handle(...$dependencies);

    // A duplicate public reply on someone's timeline cannot be taken back.
    expect(FakeEngagementProvider::$repliedTo)->toHaveCount(1);
});

it('records a failed reply and notifies the workspace when the provider refuses', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    FakeEngagementProvider::failRepliesFor(
        $account,
        new ProviderApiException(
            'facebook',
            400,
            null,
            UserFacingError::make('comment_closed', 'This comment thread is closed on Facebook.', 'The network rejected the reply.', false),
        ),
    );

    $reply = replies()->reply($comment, $user, 'On it');

    (new SendCommentReplyJob((int) $reply->getKey(), (int) $tenant->getKey()))->handle(
        app(\App\Services\Social\SocialProviderRegistry::class),
        replies(),
        app(\App\Services\Engagement\NotificationService::class),
    );

    $reply->refresh();

    expect($reply->statusEnum())->toBe(CommentSyncStatus::Failed)
        ->and($reply->error_message)->toContain('comment thread is closed')
        ->and($reply->sent_at)->toBeNull()
        ->and(SocialHubNotification::query()->where('type', 'comment.reply_failed')->count())->toBe(1)
        ->and(FakeEngagementProvider::$repliedTo)->toBe([]);
});

it('sends the reply to the comments own account even if the row disagrees', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $other = engagementAccount($tenant, SocialPlatform::Instagram);

    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    $reply = CommentReply::create([
        'comment_id' => $comment->getKey(),
        'user_id' => $user->getKey(),
        // A denormalised copy naming a different account: the job must resolve
        // the account through the comment, so a tampered row cannot redirect a
        // reply onto someone else's timeline.
        'social_account_id' => $other->getKey(),
        'provider' => 'instagram',
        'content' => 'On it',
        'status' => CommentSyncStatus::Pending->value,
    ]);

    (new SendCommentReplyJob((int) $reply->getKey(), (int) $tenant->getKey()))->handle(
        app(\App\Services\Social\SocialProviderRegistry::class),
        replies(),
        app(\App\Services\Engagement\NotificationService::class),
    );

    expect($reply->refresh()->statusEnum())->toBe(CommentSyncStatus::Sent)
        ->and(FakeEngagementProvider::$repliedTo)->toBe([(int) $account->getKey()]);
});

it('fails the reply when the connected account is no longer connected', function (): void {
    Queue::fake();

    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    $reply = replies()->reply($comment, $user, 'On it');

    $account->markStatus(\App\Enums\SocialAccountStatus::Revoked, 'Access revoked by the provider.');

    FakeEngagementProvider::failRepliesFor(
        $account,
        new TokenRevokedException('facebook'),
    );

    (new SendCommentReplyJob((int) $reply->getKey(), (int) $tenant->getKey()))->handle(
        app(\App\Services\Social\SocialProviderRegistry::class),
        replies(),
        app(\App\Services\Engagement\NotificationService::class),
    );

    expect($reply->refresh()->statusEnum())->toBe(CommentSyncStatus::Failed)
        ->and($reply->error_message)->toContain('disconnected')
        ->and(FakeEngagementProvider::$repliedTo)->toBe([]);
});

// Input hygiene

it('rejects an empty reply', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    expect(fn () => replies()->reply($comment, $user, "   \n  "))
        ->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

it('strips control characters from a reply before it reaches the provider', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    $reply = replies()->reply($comment, $user, "Hello\x00 there\x07");

    expect($reply->content)->toBe('Hello there');
});

it('routes the reply to the comments own account, not another one', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $other = engagementAccount($tenant, SocialPlatform::Instagram);

    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    $reply = replies()->reply($comment, $user, 'Hello');

    expect((int) $reply->social_account_id)->toBe((int) $account->getKey())
        ->and((int) $reply->social_account_id)->not->toBe((int) $other->getKey());
});

it('rebuilds the provider comment from what we stored, not from the request', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentOn($tenant, $account, 'The actual comment');

    $providerComment = replies()->providerCommentFor($comment);

    expect($providerComment->providerCommentId)->toBe($comment->provider_comment_id)
        ->and($providerComment->content)->toBe('The actual comment')
        ->and($providerComment->providerPostId)->toBe($comment->postVariant->provider_post_id);
});

it('never puts a token in a failed reply notification', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentOn($tenant, $account);
    $user = engagementUser($tenant);

    FakeEngagementProvider::failRepliesFor(
        $account,
        new ProviderApiException(
            'facebook',
            400,
            null,
            UserFacingError::make('comment_closed', 'This comment thread is closed on Facebook.', 'The network rejected the reply.', false),
        ),
    );

    $reply = replies()->reply($comment, $user, 'On it');

    (new SendCommentReplyJob((int) $reply->getKey(), (int) $tenant->getKey()))->handle(
        app(\App\Services\Social\SocialProviderRegistry::class),
        replies(),
        app(\App\Services\Engagement\NotificationService::class),
    );

    $encoded = json_encode(SocialHubNotification::query()->first()->toArray());

    expect($encoded)->not->toContain('access_token')
        ->not->toContain('refresh_token');
});
