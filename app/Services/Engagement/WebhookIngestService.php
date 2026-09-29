<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\Comment;
use App\Models\SocialAccount;
use App\Models\WebhookEvent;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Accepts provider webhooks.
 *
 * Two invariants matter. First, the payload is trusted only after the provider
 * has verified its own signature over the *raw* body — a JSON re-encode can
 * reorder keys and would invalidate an HMAC computed over the original bytes.
 * Second, an event id is claimed before any effect happens, so the same
 * delivery arriving three times applies once.
 */
final class WebhookIngestService
{
    /** How far a delivery's timestamp may lag before it is treated as a replay. */
    public const TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly SocialProviderRegistry $registry,
        private readonly NotificationService $notifications,
    ) {}

    public function ingest(VerifiedWebhookRequest $request): WebhookIngestResult
    {
        if (trim($request->rawBody) === '') {
            return WebhookIngestResult::refused(WebhookIngestResult::REASON_INVALID_SIGNATURE, 401);
        }

        $refusal = $this->verify($request);

        if ($refusal !== null) {
            return $refusal;
        }

        $eventId = $this->eventId($request->payload);

        // Claim the id first. Everything after this point is idempotent, and a
        // redelivery short-circuits here without touching the payload.
        try {
            $event = WebhookEvent::query()->create([
                'provider' => $request->provider,
                'event_id' => $eventId,
                'event_type' => $this->eventType($request->payload),
                'payload' => $this->scrub($request->payload),
                'processed' => false,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicate($exception)) {
                throw $exception;
            }

            $existing = WebhookEvent::query()
                ->where('provider', $request->provider)
                ->where('event_id', $eventId)
                ->first();

            return WebhookIngestResult::duplicate((int) ($existing?->getKey() ?? 0));
        }

        $outcome = $this->apply($event, $request);

        $event->forceFill([
            'processed' => true,
            'processed_at' => now(),
            'error_message' => null,
        ])->save();

        return $outcome;
    }

    /**
     * Re-apply an event that was recorded but not yet applied.
     *
     * Used by the queued job when the HTTP request that ingested the delivery
     * could not finish. The event id is already claimed, so this is a single
     * apply pass and not a second ingest.
     */
    public function replay(WebhookEvent $event): WebhookIngestResult
    {
        $payload = (array) $event->payload;

        $request = new VerifiedWebhookRequest(
            provider: $this->providerKey($event->provider),
            rawBody: (string) json_encode($payload),
            payload: $payload,
            headers: [],
            receivedAt: time(),
        );

        $result = $this->apply($event, $request);

        if ($result->outcome === WebhookIngestResult::OUTCOME_PROCESSED) {
            $event->forceFill([
                'processed' => true,
                'processed_at' => now(),
                'error_message' => null,
            ])->save();
        }

        return $result;
    }

    /**
     * A refused delivery is never recorded: it is not work anyone owes.
     */
    private function verify(VerifiedWebhookRequest $request): ?WebhookIngestResult
    {
        try {
            $provider = $this->registry->get($request->provider);
        } catch (Throwable) {
            return WebhookIngestResult::refused(WebhookIngestResult::REASON_UNKNOWN_PROVIDER, 401);
        }

        if ($this->timestampSkew($request) > self::TOLERANCE_SECONDS) {
            return WebhookIngestResult::refused(WebhookIngestResult::REASON_STALE_TIMESTAMP, 400);
        }

        try {
            $verified = $provider->verifyWebhook($request);
        } catch (Throwable) {
            return WebhookIngestResult::refused(WebhookIngestResult::REASON_INVALID_SIGNATURE, 401);
        }

        return $verified === true
            ? null
            : WebhookIngestResult::refused(WebhookIngestResult::REASON_INVALID_SIGNATURE, 401);
    }

    private function timestampSkew(VerifiedWebhookRequest $request): int
    {
        $timestamp = $request->header('x-hub-signature-timestamp');

        if ($timestamp === null || ! is_numeric($timestamp)) {
            return 0;
        }

        return abs($request->receivedAt - (int) $timestamp);
    }

    /**
     * Apply one verified event. An event naming an account this workspace does
     * not have is recorded but not applied, so it is never retried forever.
     */
    private function apply(WebhookEvent $event, VerifiedWebhookRequest $request): WebhookIngestResult
    {
        $account = $this->resolveAccount($request);

        if ($account === null) {
            return WebhookIngestResult::unmatched((int) $event->getKey());
        }

        try {
            $this->handle($event, $account, $request);
        } catch (Throwable $exception) {
            $event->forceFill([
                'processed' => false,
                'error_message' => \Illuminate\Support\Str::limit($exception->getMessage(), 500, ''),
            ])->save();

            $this->notifications->notifyMembers(
                (int) $account->tenant_id,
                NotificationService::WEBHOOK_FAILED,
                sprintf('A %s update could not be applied', $event->provider),
                'This does not affect scheduled publishing. The update will be picked up on the next sync.',
                ['event_id' => $event->event_id],
                'normal',
            );

            Log::warning('socialhub.webhook.apply_failed', [
                'provider' => $event->provider,
                'event_id' => $event->event_id,
                'exception' => $exception::class,
            ]);
        }

        return WebhookIngestResult::processed((int) $event->getKey());
    }

    private function handle(WebhookEvent $event, SocialAccount $account, VerifiedWebhookRequest $request): void
    {
        $field = $this->firstField($request->payload);
        $value = $this->firstValue($request->payload);

        match ($field) {
            'permissions' => $this->handlePermissionChange($account, $value),
            'feed' => $this->handleFeedChange($account, $value),
            // An event type this version does not understand is recorded and
            // done. Treating it as a failure would retry it forever.
            default => null,
        };

        unset($event);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handlePermissionChange(SocialAccount $account, array $value): void
    {
        if (($value['verb'] ?? null) !== 'revoke') {
            return;
        }

        $account->token?->delete();
        $account->markStatus(SocialAccountStatus::Revoked, 'The platform revoked this authorization.');

        $this->notifications->tokenRevoked(
            $account,
            'The platform withdrew access to this page.',
            'webhook-revoked:'.$account->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handleFeedChange(SocialAccount $account, array $value): void
    {
        if (($value['verb'] ?? null) !== 'add') {
            return;
        }

        $commentId = $value['comment_id'] ?? null;
        $postId = $value['post_id'] ?? null;

        // A comment we cannot attribute to a post we published is not an
        // error; it is simply not ours to store.
        if (! is_string($commentId) || $commentId === '' || ! is_string($postId) || $postId === '') {
            return;
        }

        $variant = $account->postVariants()->where('provider_post_id', $postId)->first();

        if ($variant === null) {
            return;
        }

        $existing = Comment::query()
            ->withoutGlobalScopes()
            ->where('provider', $account->provider->value)
            ->where('provider_comment_id', $commentId)
            ->first();

        if ($existing !== null) {
            return;
        }

        $comment = Comment::query()->create([
            'tenant_id' => $account->tenant_id,
            'post_variant_id' => $variant->getKey(),
            'social_account_id' => $account->getKey(),
            'provider' => $account->provider->value,
            'provider_comment_id' => $commentId,
            'author_provider_id' => (string) ($value['from']['id'] ?? $commentId),
            'author_display_name' => $value['from']['name'] ?? null,
            'content' => (string) ($value['message'] ?? ''),
            'provider_created_at' => isset($value['created_time']) && is_numeric($value['created_time'])
                ? (new \DateTimeImmutable)->setTimestamp((int) $value['created_time'])
                : now(),
            'synced_at' => now(),
        ]);

        $this->notifications->notifyMembers(
            (int) $comment->tenant_id,
            NotificationService::COMMENT_NEW,
            sprintf('New comment on %s', $account->provider?->label() ?? 'your post'),
            sprintf(
                '%s commented: “%s”',
                $comment->authorDisplayLabel(),
                \Illuminate\Support\Str::limit((string) $comment->content, 140),
            ),
            ['comment_id' => $comment->id, 'provider' => $account->provider?->value],
            'normal',
            route('socialhub.comments.show', $comment),
        );
    }

    private function resolveAccount(VerifiedWebhookRequest $request): ?SocialAccount
    {
        $providerId = $this->providerAccountId($request->payload);

        if ($providerId === null) {
            return null;
        }

        return SocialAccount::query()
            ->where('provider', $request->provider)
            ->where('provider_account_id', $providerId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerAccountId(array $payload): ?string
    {
        $entry = $payload['entry'][0] ?? null;

        if (is_array($entry) && isset($entry['id'])) {
            return (string) $entry['id'];
        }

        foreach (['page_id', 'account_id', 'channel_id', 'user_id'] as $key) {
            if (isset($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function firstField(array $payload): string
    {
        $entry = $payload['entry'][0] ?? null;

        if (is_array($entry) && isset($entry['changes'][0]['field'])) {
            return (string) $entry['changes'][0]['field'];
        }

        return (string) ($payload['field'] ?? $payload['type'] ?? 'unknown');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function firstValue(array $payload): array
    {
        $entry = $payload['entry'][0] ?? null;

        if (is_array($entry) && isset($entry['changes'][0]['value']) && is_array($entry['changes'][0]['value'])) {
            return $entry['changes'][0]['value'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventId(array $payload): string
    {
        foreach (['event_id', 'entry_id', 'delivery_id', 'message_id', 'id'] as $key) {
            if (isset($payload[$key]) && (is_string($payload[$key]) || is_int($payload[$key]))) {
                return (string) $payload[$key];
            }
        }

        return 'sha256:'.hash('sha256', (string) json_encode($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventType(array $payload): string
    {
        return $this->firstField($payload);
    }

    /**
     * Keep the payload for an operator to inspect, minus anything that could be
     * a credential. A delivery is attacker-influenced data.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scrub(array $payload, int $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }

        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/(access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization|code[_-]?verifier|signature)$/i', $key)) {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = is_array($value) ? $this->scrub($value, $depth + 1) : $value;
        }

        return $clean;
    }

    /**
     * A duplicate is specifically a unique-index violation, not any integrity
     * error. SQLite reports every constraint failure as SQLSTATE 23000, so the
     * message has to be checked too or a not-null violation would be silently
     * swallowed as "already handled".
     */
    private function isDuplicate(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (in_array($exception->getCode(), ['23505'], true)) {
            return true;
        }

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'duplicate key');
    }

    /**
     * `provider` is a backed enum on the model and a plain string on the wire.
     */
    private function providerKey(mixed $provider): string
    {
        return $provider instanceof SocialPlatform ? $provider->value : (string) $provider;
    }
}
