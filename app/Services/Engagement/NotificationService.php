<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\SocialAccount;
use App\Models\SocialHubNotification;
use App\Models\Tenant;
use App\Models\User;

/**
 * Writes rows to `socialhub_notifications`.
 *
 * Notification bodies are composed from provider-supplied labels only. A
 * token, secret or raw provider payload is never interpolated into one,
 * because notifications are the one place a user is guaranteed to read.
 */
final class NotificationService
{
    public const PUBLISH_SUCCEEDED = 'publishing.succeeded';

    public const PUBLISH_FAILED = 'publishing.failed';

    public const TOKEN_EXPIRING = 'social_account.token_expiring';

    public const TOKEN_EXPIRED = 'social_account.token_expired';

    public const ACCOUNT_DISCONNECTED = 'social_account.token_revoked';

    public const ACCOUNT_NEEDS_ATTENTION = 'social_account.needs_attention';

    public const COMMENT_NEW = 'comment.new';

    public const REPLY_FAILED = 'comment.reply_failed';

    public const WEBHOOK_FAILED = 'webhook.processing_failed';

    public function notify(
        int $tenantId,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?User $user = null,
        string $priority = 'normal',
        ?string $actionUrl = null,
    ): SocialHubNotification {
        return SocialHubNotification::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user?->id,
            'type' => $type,
            'title' => $title,
            'message' => $this->redact($message),
            'data' => $this->redactArray($data),
            'action_url' => $actionUrl,
            'priority' => $priority,
        ]);
    }

    /**
     * Record a workspace-level notice.
     *
     * A single broadcast row rather than one per member: the notification
     * centre already shows broadcast rows to everybody, and fanning out would
     * multiply the row count by the size of the team and make "the workspace
     * was told once" impossible to assert.
     *
     * @param  array<string, mixed>  $data
     */
    public function notifyMembers(
        int $tenantId,
        string $type,
        string $title,
        string $message,
        array $data = [],
        string $priority = 'normal',
        ?string $actionUrl = null,
        array $extra = [],
    ): SocialHubNotification {
        return $this->notify(
            $tenantId,
            $type,
            $title,
            $message,
            $data + $extra,
            null,
            $priority,
            $actionUrl,
        );
    }

    public function unreadCount(int $tenantId, ?User $user): int
    {
        return SocialHubNotification::query()
            ->where('tenant_id', $tenantId)
            ->when($user === null, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', $user->id))
            ->where('is_read', false)
            ->count();
    }

    /**
     * Warn that an authorization is about to lapse.
     *
     * A refresh sweep can run every few minutes, so the warning is throttled to
     * once an hour per account: a returning null is the signal that the caller
     * should not notify again.
     */
    public function tokenExpiring(SocialAccount $account, \DateTimeInterface $expiresAt): ?SocialHubNotification
    {
        $throttle = sprintf('socialhub:notify:token-expiring:%d', (int) $account->getKey());

        if (! \Illuminate\Support\Facades\Cache::add($throttle, true, now()->addHour())) {
            return null;
        }

        return $this->notifyMembersOnce(
            (int) $account->tenant_id,
            self::TOKEN_EXPIRING,
            sprintf('%s authorization expires soon', $this->platformLabel($account)),
            sprintf('Access expires %s. Publishing will pause until it is renewed.', $expiresAt->format('j M Y, H:i')),
            ['account_id' => (int) $account->getKey(), 'provider' => $this->platformLabel($account)],
            'high',
            route('socialhub.accounts.index'),
        );
    }

    public function tokenRevoked(SocialAccount $account, string $reason, string $dedupeKey): SocialHubNotification
    {
        return $this->notifyMembersOnce(
            (int) $account->tenant_id,
            self::ACCOUNT_DISCONNECTED,
            sprintf('%s access was revoked', $this->platformLabel($account)),
            'Reconnect the account to resume publishing to it. '.$reason,
            ['account_id' => (int) $account->getKey(), 'provider' => $this->platformLabel($account)],
            'critical',
            route('socialhub.accounts.index'),
            $dedupeKey,
        );
    }

    public function tokenExpired(SocialAccount $account, string $reason, string $dedupeKey): SocialHubNotification
    {
        return $this->notifyMembersOnce(
            (int) $account->tenant_id,
            self::TOKEN_EXPIRED,
            sprintf('%s authorization expired', $this->platformLabel($account)),
            'Reconnect the account to resume publishing to it. '.$reason,
            ['account_id' => (int) $account->getKey(), 'provider' => $this->platformLabel($account)],
            'high',
            route('socialhub.accounts.index'),
            $dedupeKey,
        );
    }

    /**
     * Notify exactly once for a condition that is otherwise retried forever.
     * Without the dedupe key a revoked account would raise a new notification
     * on every sweep for the rest of the workspace's life.
     */
    private function notifyMembersOnce(
        int $tenantId,
        string $type,
        string $title,
        string $message,
        array $data,
        string $priority,
        ?string $actionUrl,
        ?string $dedupeKey = null,
    ): SocialHubNotification {
        if ($dedupeKey !== null) {
            $cacheKey = 'socialhub:notify:'.$dedupeKey;

            if (! \Illuminate\Support\Facades\Cache::add($cacheKey, true, now()->addDays(30))) {
                $existing = SocialHubNotification::query()
                    ->where('tenant_id', $tenantId)
                    ->ofType($type)
                    ->latest()
                    ->first();

                return $existing ?? $this->notify($tenantId, $type, $title, $message, $data, null, $priority, $actionUrl);
            }
        }

        return $this->notify($tenantId, $type, $title, $message, $data, null, $priority, $actionUrl);
    }

    private function platformLabel(SocialAccount $account): string
    {
        return $account->provider?->label() ?? 'The social account';
    }

    /**
     * Belt-and-braces guard: even if a caller hands us a provider exception
     * message that happens to contain a token, it never reaches a row here.
     */
    private function redact(string $message): string
    {
        $pattern = '/(access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization|code[_-]?verifier|password|api[_-]?key)\s*[:=]\s*\S+/i';

        return (string) preg_replace($pattern, '$1=[redacted]', $message);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redactArray(array $data): array
    {
        $sensitive = '/(access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization|code[_-]?verifier|password|api[_-]?key)$/i';

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match($sensitive, $key)) {
                $data[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactArray($value);
            }
        }

        return $data;
    }

    public function tenantName(int $tenantId): string
    {
        return (string) (Tenant::query()->find($tenantId)?->name ?? 'Workspace');
    }
}
