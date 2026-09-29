<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\SocialAccountStatus;
use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Models\Comment;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\Data\ProviderComment;
use App\Services\Social\SocialProviderRegistry;
use Throwable;

/**
 * Pulls one account's comments and reconciles them into the local table.
 *
 * The reconciliation key is `(provider, provider_comment_id)`, so a comment
 * arriving twice — a poll overlapping a webhook, or a retried job — updates
 * the existing row instead of duplicating it.
 *
 * A comment is only stored when it belongs to a post **this account**
 * published. A comment that arrives on a post id belonging to a different
 * account is skipped rather than attributed on a guess: mis-filing someone's
 * comment under the wrong account is worse than not showing it.
 *
 * Failures are returned, not thrown, so a caller can loop over many accounts
 * without a try/catch each, and so one account's outage cannot stop the others.
 */
final class CommentSyncService
{
    private const PAGE_LIMIT_SKIP = 'page_limit_reached';

    public function __construct(
        private readonly SocialProviderRegistry $registry,
        private readonly NotificationService $notifications,
    ) {}

    public function syncAccount(SocialAccount $account, ?int $maxComments = null): CommentSyncResult
    {
        if (! $account->isActive()) {
            return new CommentSyncResult(
                socialAccountId: (int) $account->getKey(),
                failure: 'The account is not connected.',
                failureCode: 'account_inactive',
            );
        }

        try {
            $provider = $this->providerFor($account);
        } catch (Throwable) {
            return new CommentSyncResult(
                socialAccountId: (int) $account->getKey(),
                failure: 'The provider is not available.',
                failureCode: 'provider_unavailable',
            );
        }

        // Gate on the declared capability before spending a request. A network
        // with no comments API is a stable fact, not an incident.
        if (! $provider->getSupportedFeatures()->comments) {
            return new CommentSyncResult(
                socialAccountId: (int) $account->getKey(),
                failure: sprintf(
                    '%s does not expose a comments API.',
                    $account->provider?->label() ?? 'This network',
                ),
                failureCode: 'unsupported_capability',
            );
        }

        try {
            $comments = $provider->getComments($account, $this->providerPostIdFor($account));
        } catch (UnsupportedCapabilityException $exception) {
            return new CommentSyncResult(
                socialAccountId: (int) $account->getKey(),
                failure: $exception->userMessage(),
                failureCode: 'unsupported_capability',
            );
        } catch (RateLimitException $exception) {
            return new CommentSyncResult(
                socialAccountId: (int) $account->getKey(),
                failure: $exception->userMessage(),
                failureCode: 'rate_limited',
            );
        } catch (TokenRevokedException $exception) {
            $this->markRevoked($account, $exception);

            return new CommentSyncResult(
                socialAccountId: (int) $account->getKey(),
                failure: $exception->userMessage(),
                failureCode: 'token_revoked',
            );
        } catch (ProviderApiException $exception) {
            return new CommentSyncResult(
                socialAccountId: (int) $account->getKey(),
                failure: $exception->userMessage(),
                failureCode: 'provider_api_error',
            );
        } catch (Throwable $exception) {
            return new CommentSyncResult(
                socialAccountId: (int) $account->getKey(),
                failure: $exception->getMessage(),
                failureCode: 'unexpected_error',
            );
        }

        return $this->reconcile($account, $comments, $maxComments);
    }

    /**
     * @param  list<ProviderComment>  $comments
     */
    private function reconcile(SocialAccount $account, array $comments, ?int $maxComments): CommentSyncResult
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $markedHidden = 0;
        $markedDeleted = 0;
        $skipped = [];
        $hitLimit = false;

        foreach ($comments as $providerComment) {
            if ($maxComments !== null && ($created + $updated) >= $maxComments) {
                $hitLimit = true;

                break;
            }

            $variant = $this->resolveVariant($account, $providerComment);

            if ($variant === null) {
                $skipped[] = (string) ($providerComment->providerPostId ?: $providerComment->providerCommentId);

                continue;
            }

            $outcome = $this->upsert($account, $variant, $providerComment);

            match ($outcome['state']) {
                'created' => $created++,
                'updated' => $updated++,
                default => $unchanged++,
            };

            $markedHidden += $outcome['marked_hidden'];
            $markedDeleted += $outcome['marked_deleted'];

            if ($outcome['state'] === 'created') {
                $this->notifyNewComment($outcome['comment']);
            }
        }

        if ($hitLimit) {
            $skipped[] = self::PAGE_LIMIT_SKIP;
        }

        // Only a run that actually completed updates the marker, so a failed
        // sync is never mistaken for a successful one.
        $account->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

        return new CommentSyncResult(
            socialAccountId: (int) $account->getKey(),
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            markedHidden: $markedHidden,
            markedDeleted: $markedDeleted,
            skipped: $skipped,
        );
    }

    /**
     * @return array{state: string, comment: Comment, marked_hidden: int, marked_deleted: int}
     */
    private function upsert(SocialAccount $account, \App\Models\PostVariant $variant, ProviderComment $providerComment): array
    {
        $isHidden = $this->isHidden($providerComment);
        $isDeleted = $this->isDeleted($providerComment);

        $existing = Comment::query()
            ->withoutGlobalScopes()
            ->where('provider', $account->provider->value)
            ->where('provider_comment_id', $providerComment->providerCommentId)
            ->first();

        $attributes = [
            'post_variant_id' => $variant->getKey(),
            'social_account_id' => $account->getKey(),
            'content' => $providerComment->content,
            'like_count' => $providerComment->likeCount,
            'reply_count' => $providerComment->replyCount,
            'is_hidden' => $isHidden,
            'is_deleted' => $isDeleted,
            'author_provider_id' => (string) ($providerComment->authorProviderId ?? ''),
            'author_username' => $providerComment->authorUsername,
            'author_display_name' => $providerComment->authorDisplayName,
            'author_avatar_url' => $providerComment->authorAvatarUrl,
            'parent_comment_id' => $this->resolveParent($account, $providerComment),
            'synced_at' => now(),
        ];

        if ($existing === null) {
            $comment = Comment::query()->create($attributes + [
                'tenant_id' => $account->tenant_id,
                'provider' => $account->provider->value,
                'provider_comment_id' => $providerComment->providerCommentId,
                // The creation time is only ever set once. A later sync that
                // re-sends the comment carries a fresh "now" from the provider
                // adapter, and rewriting it would make every comment look
                // edited and reorder the inbox.
                'provider_created_at' => $providerComment->createdAt ?? now(),
            ]);

            return [
                'state' => 'created',
                'comment' => $comment,
                'marked_hidden' => $isHidden ? 1 : 0,
                'marked_deleted' => $isDeleted ? 1 : 0,
            ];
        }

        $wasHidden = (bool) $existing->is_hidden;
        $wasDeleted = (bool) $existing->is_deleted;

        $existing->fill($attributes);
        $existing->save();

        $changed = array_diff_key($existing->getChanges(), [
            'synced_at' => null,
            'updated_at' => null,
        ]) !== [];

        return [
            'state' => $changed ? 'updated' : 'unchanged',
            'comment' => $existing,
            'marked_hidden' => ! $wasHidden && $isHidden ? 1 : 0,
            'marked_deleted' => ! $wasDeleted && $isDeleted ? 1 : 0,
        ];
    }

    /**
     * Only accept a comment when this account published the post it is on.
     */
    private function resolveVariant(SocialAccount $account, ProviderComment $providerComment): ?\App\Models\PostVariant
    {
        $postId = $providerComment->providerPostId;

        if ($postId === null || $postId === '') {
            return null;
        }

        return $account->postVariants()
            ->where('provider_post_id', $postId)
            ->first();
    }

    private function resolveParent(SocialAccount $account, ProviderComment $providerComment): ?int
    {
        $parentProviderId = $providerComment->parentProviderCommentId;

        if ($parentProviderId === null || $parentProviderId === '') {
            return null;
        }

        return Comment::query()
            ->withoutGlobalScopes()
            ->where('provider', $account->provider->value)
            ->where('provider_comment_id', $parentProviderId)
            ->value('id');
    }

    private function isHidden(ProviderComment $comment): bool
    {
        return (bool) (($comment->raw['is_hidden'] ?? $comment->raw['isHidden'] ?? false));
    }

    private function isDeleted(ProviderComment $comment): bool
    {
        return (bool) (($comment->raw['is_deleted'] ?? $comment->raw['isDeleted'] ?? false));
    }

    private function providerPostIdFor(SocialAccount $account): ?string
    {
        unset($account);

        return null;
    }

    private function providerFor(SocialAccount $account): SocialProviderInterface
    {
        return $this->registry->get((string) $account->provider->value);
    }

    private function markRevoked(SocialAccount $account, TokenRevokedException $exception): void
    {
        $account->token?->delete();
        $account->markStatus(SocialAccountStatus::Revoked, $exception->userMessage());

        // The dedupe key means a workspace is told once, not on every sweep.
        $this->notifications->tokenRevoked(
            $account,
            $exception->userMessage(),
            'comment-sync-revoked:'.$account->getKey(),
        );
    }

    private function notifyNewComment(Comment $comment): void
    {
        $this->notifications->notifyMembers(
            (int) $comment->tenant_id,
            NotificationService::COMMENT_NEW,
            sprintf('New comment on %s', $comment->socialAccount?->provider?->label() ?? 'your post'),
            sprintf(
                '%s commented: “%s”',
                $comment->authorDisplayLabel(),
                \Illuminate\Support\Str::limit($comment->content, 140),
            ),
            [
                'comment_id' => $comment->id,
                'provider' => $comment->provider?->value,
            ],
            'normal',
            route('socialhub.comments.show', $comment),
        );
    }
}
