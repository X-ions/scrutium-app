<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\CommentSyncStatus;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Jobs\Engagement\SendCommentReplyJob;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\User;
use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\Data\ProviderComment;
use App\Services\Social\Data\UserFacingError;
use App\Services\Social\SocialProviderRegistry;
use Throwable;

/**
 * Queues a reply to a comment.
 *
 * Replying is a provider capability, not a universal operation: Pinterest pins
 * expose no public comment API at all. The gate throws before anything is
 * written or queued, so the UI can explain why the box is missing instead of
 * accepting text and failing at the provider.
 */
final class ReplyService
{
    public function __construct(
        private readonly SocialProviderRegistry $registry,
        private readonly NotificationService $notifications,
    ) {}

    public function reply(Comment $comment, User $actor, string $body): CommentReply
    {
        // Control characters are stripped before the text is stored, not just
        // before it is sent, so nothing downstream can be confused by them.
        $body = $this->sanitize($body);

        if ($body === '') {
            throw new \InvalidArgumentException('A reply cannot be empty.');
        }

        $this->assertCanReply($comment);

        $reply = CommentReply::query()->create([
            'comment_id' => $comment->id,
            'user_id' => $actor->id,
            'social_account_id' => (int) $comment->social_account_id,
            'provider' => (string) $comment->provider?->value,
            'content' => $body,
            'status' => CommentSyncStatus::Pending->value,
        ]);

        // Queued, never called inline: a provider call inside the request
        // would make the reply depend on the user waiting on it.
        SendCommentReplyJob::dispatch((int) $reply->getKey(), (int) $comment->tenant_id);

        return $reply;
    }

    /**
     * Whether the reply control should be shown at all.
     */
    public function canReply(Comment $comment): bool
    {
        try {
            return $this->providerFor($comment)->getSupportedFeatures()->commentReplies;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Why the reply control is missing, phrased for the user.
     */
    public function refusalReason(Comment $comment): ?string
    {
        if ($this->canReply($comment)) {
            return null;
        }

        $label = $comment->provider?->label() ?? ucfirst((string) $comment->provider?->value) ?: 'this network';

        return sprintf(
            'Replying is not available on %s. Open the original post to reply there.',
            $label,
        );
    }

    /**
     * Execute a queued reply. Called only from the job.
     *
     * Failures are recorded on the reply and notified, not rethrown: a reply
     * that a network refuses is a resolved outcome for this row, and letting
     * the exception escape would retry it for no reason.
     */
    public function send(CommentReply $reply): void
    {
        $comment = $reply->comment;

        if ($comment === null) {
            $this->markFailed($reply, 'The comment this reply belonged to no longer exists.');

            return;
        }

        // A job that runs twice must not double-post, and a reply already
        // recorded as failed must not raise a second notification either. Both
        // are terminal outcomes of an attempt that already happened.
        if (in_array($reply->statusEnum(), [CommentSyncStatus::Sent, CommentSyncStatus::Failed], true)) {
            return;
        }

        // The account is resolved through the comment, never through the
        // denormalised columns on the reply row, so a tampered row cannot
        // redirect a reply onto somebody else's timeline.
        $account = $comment->socialAccount;

        if ($account === null) {
            $this->markFailed($reply, 'The social account for this comment is disconnected.');

            return;
        }

        if (! $account->isActive()) {
            $this->markFailed(
                $reply,
                sprintf(
                    'The %s account is disconnected. Reconnect it to reply to this comment.',
                    $comment->provider?->label() ?? 'social',
                ),
            );

            return;
        }

        try {
            $this->providerFor($comment)->replyToComment(
                $account,
                $this->providerCommentFor($comment),
                (string) $reply->content,
            );
        } catch (Throwable $exception) {
            $message = $exception->userFacingError?->userMessage ?? $exception->getMessage();

            $this->markFailed($reply, $message);

            $this->notifications->notifyMembers(
                (int) $comment->tenant_id,
                NotificationService::REPLY_FAILED,
                'A comment reply could not be sent',
                $exception->userFacingError?->userMessage ?? 'The network rejected the reply. Try again in a moment.',
                ['comment_id' => $comment->id, 'reply_id' => $reply->id],
                'high',
            );

            return;
        }

        $reply->forceFill([
            'status' => CommentSyncStatus::Sent->value,
            'sent_at' => now(),
            'error_message' => null,
        ])->save();
    }

    private function markFailed(CommentReply $reply, string $message): void
    {
        $reply->forceFill([
            'status' => CommentSyncStatus::Failed->value,
            'error_message' => \Illuminate\Support\Str::limit($message, 500, ''),
        ])->save();
    }

    private function assertCanReply(Comment $comment): void
    {
        $capabilities = $this->providerFor($comment)->getSupportedFeatures();

        if ($capabilities->commentReplies) {
            return;
        }

        $platform = $comment->provider?->label() ?? ucfirst((string) $comment->provider?->value) ?: 'this network';

        throw new UnsupportedCapabilityException(
            'commentReplies',
            (string) $comment->provider?->value,
            UserFacingError::make(
                'unsupported_capability',
                sprintf('Replying is not available on %s.', $platform),
                sprintf('Provider "%s" exposes no comment reply endpoint.', (string) $comment->provider?->value),
                false,
                sprintf('Open the original post on %s to reply there.', $platform),
            ),
        );
    }

    /**
     * Rebuild the provider's view of a comment from what we stored.
     *
     * The request never contributes to this: a crafted reply body must not be
     * able to name a different comment, post or account.
     */
    public function providerCommentFor(Comment $comment): ProviderComment
    {
        return new ProviderComment(
            provider: (string) $comment->provider?->value,
            providerCommentId: (string) $comment->provider_comment_id,
            providerPostId: (string) ($comment->postVariant?->provider_post_id ?? ''),
            content: (string) $comment->content,
            parentProviderCommentId: $comment->parent?->provider_comment_id,
            authorProviderId: (string) $comment->author_provider_id,
            authorUsername: $comment->author_username,
            authorDisplayName: $comment->author_display_name,
            likeCount: (int) $comment->like_count,
            replyCount: (int) $comment->reply_count,
            createdAt: $comment->provider_created_at,
        );
    }

    /**
     * Drop control characters and collapse the whitespace they leave behind,
     * keeping the visible text the user actually typed.
     */
    private function sanitize(string $body): string
    {
        $stripped = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $stripped));
    }

    private function providerFor(Comment $comment): SocialProviderInterface
    {
        return $this->registry->get((string) $comment->provider?->value);
    }
}
