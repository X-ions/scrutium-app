<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\SocialAccountStatus;
use App\Events\Engagement\SocialAccountTokenInvalidated;
use App\Exceptions\Social\AuthenticationException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\SocialProviderException;
use App\Exceptions\Social\TokenExpiredException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Services\Social\Data\TokenSet;
use App\Services\Social\SocialProviderRegistry;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps connected accounts' access tokens alive.
 *
 * Refresh is terminal in both directions, and that is the point of this class:
 *
 * - A refresh that succeeds writes the new access token, the new expiry and, when
 *   the provider rotates refresh tokens, the new refresh token. The old refresh
 *   token is kept when the provider does not send one, because several
 *   providers invalidate the previous one on use and never return it again.
 * - A refresh rejected with `invalid_grant` (or any revocation) marks the
 *   account `revoked`, notifies the workspace **once**, and returns. It does not
 *   retry, because the credential will never come back by asking again, and a
 *   loop against a provider that has already said no is a good way to get the
 *   whole app's rate limit suspended.
 * - A token that cannot be refreshed before it expires marks the account
 *   `expired` and notifies once.
 *
 * Concurrency: the whole sweep is guarded by a cache lock, and each account is
 * additionally locked by id, so a scheduler tick that overlaps with a manual run
 * cannot refresh the same account twice. Both locks are released in `finally`,
 * including on failure, so one poisoned account cannot wedge the sweep.
 */
class TokenRefreshService
{
    public const SWEEP_LOCK = 'socialhub:tokens:refresh-sweep';

    public const SWEEP_LOCK_SECONDS = 300;

    public const ACCOUNT_LOCK_PREFIX = 'socialhub:tokens:refresh:account:';

    public const ACCOUNT_LOCK_SECONDS = 120;

    /**
     * Accounts whose stored token expires within this window are refreshed.
     */
    public const DEFAULT_WINDOW_HOURS = 24;

    /**
     * @return array{refreshed: int, skipped: int, revoked: int, expired: int, failed: int, lock_held: bool}
     */
    public function refreshExpiring(?int $windowHours = null, ?int $tenantId = null): array
    {
        $window = max(1, $windowHours ?? self::DEFAULT_WINDOW_HOURS);
        $deadline = now()->addHours($window);

        $summary = [
            'refreshed' => 0,
            'skipped' => 0,
            'revoked' => 0,
            'expired' => 0,
            'failed' => 0,
            'lock_held' => false,
        ];

        $lock = Cache::lock(self::SWEEP_LOCK, self::SWEEP_LOCK_SECONDS);

        if (! $lock->get()) {
            Log::info('SocialHub token refresh: another sweep is already running.');

            $summary['lock_held'] = true;

            return $summary;
        }

        try {
            $accounts = SocialAccount::query()
                ->withoutGlobalScopes()
                ->where('status', SocialAccountStatus::Connected->value)
                ->whereHas('token', fn ($query) => $query
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $deadline)
                    ->where('expires_at', '>', now()->subDay()))
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->orderBy('id')
                ->get();

            foreach ($accounts as $account) {
                $outcome = $this->refresh($account);

                $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
            }
        } finally {
            $lock->release();
        }

        return $summary;
    }

    /**
     * Refresh one account.
     *
     * Returns one of `refreshed`, `skipped`, `revoked`, `expired`, `failed` so a
     * caller can tally a sweep without exception handling.
     */
    public function refresh(SocialAccount $account): string
    {
        $accountKey = (int) $account->getKey();

        if (! $account->isActive()) {
            return 'skipped';
        }

        $lock = Cache::lock(self::ACCOUNT_LOCK_PREFIX.$accountKey, self::ACCOUNT_LOCK_SECONDS);

        if (! $lock->get()) {
            return 'skipped';
        }

        try {
            $token = $account->token()->first();

            if (! $token instanceof SocialAccountToken) {
                $this->markExpired($account, 'The account has no stored credentials to refresh.');

                return 'expired';
            }

            if ($token->refresh_token === null || $token->refresh_token === '') {
                // Nothing to refresh with: the only recovery is a reconnect, and
                // looping would fail identically forever.
                $this->markExpired($account, 'The account has no refresh token, so it must be reconnected.');

                return 'expired';
            }

            $provider = $this->providerFor($account);

            if ($provider === null) {
                return 'skipped';
            }

            try {
                $fresh = $provider->refreshToken($account);
            } catch (TokenRevokedException $e) {
                $this->markRevoked($account, $e->userFacingError?->userMessage ?? $e->getMessage());

                return 'revoked';
            } catch (TokenExpiredException $e) {
                $this->markExpired($account, $e->userFacingError?->userMessage ?? $e->getMessage());

                return 'expired';
            } catch (UnsupportedCapabilityException) {
                return 'skipped';
            } catch (ProviderNotConfiguredException $e) {
                Log::warning('SocialHub token refresh: the provider is not configured.', [
                    'social_account_id' => $accountKey,
                    'provider' => $account->provider?->value,
                    'error' => $e->getMessage(),
                ]);

                return 'skipped';
            } catch (RateLimitException $e) {
                Log::notice('SocialHub token refresh: the provider is throttling; the sweep will retry next run.', [
                    'social_account_id' => $accountKey,
                    'provider' => $e->provider,
                    'retry_after' => $e->retryAfter,
                ]);

                return 'failed';
            } catch (AuthenticationException $e) {
                $this->markExpired($account, $e->userFacingError?->userMessage ?? $e->getMessage());

                return 'expired';
            } catch (SocialProviderException $e) {
                Log::warning('SocialHub token refresh: the provider rejected the refresh.', [
                    'social_account_id' => $accountKey,
                    'provider' => $account->provider?->value,
                    'error' => $e->getMessage(),
                ]);

                return 'failed';
            } catch (Throwable $e) {
                Log::warning('SocialHub token refresh: the refresh failed unexpectedly.', [
                    'social_account_id' => $accountKey,
                    'provider' => $account->provider?->value,
                    'error' => $e->getMessage(),
                ]);

                return 'failed';
            }

            $this->persist($token, $fresh);
            $account->forceFill(['last_error' => null])->save();

            Log::info('SocialHub token refreshed.', [
                'social_account_id' => $accountKey,
                'provider' => $account->provider?->value,
                'expires_at' => $fresh->expiresAt?->format(DateTimeInterface::ATOM),
            ]);

            return 'refreshed';
        } finally {
            $lock->release();
        }
    }

    /**
     * Warn the workspace about a token that is about to lapse, once per account
     * per hour so a scheduler running often cannot spam the bell.
     *
     * @return list<SocialAccount>
     */
    public function warnExpiring(?int $windowHours = null): array
    {
        $window = max(1, $windowHours ?? self::DEFAULT_WINDOW_HOURS);
        $deadline = now()->addHours($window);
        $notified = [];

        SocialAccount::query()
            ->withoutGlobalScopes()
            ->where('status', SocialAccountStatus::Connected->value)
            ->whereHas('token', fn ($query) => $query
                ->whereNotNull('expires_at')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', $deadline))
            ->orderBy('id')
            ->each(function (SocialAccount $account) use (&$notified): void {
                $token = $account->token()->first();

                if (! $token instanceof SocialAccountToken || $token->expires_at === null) {
                    return;
                }

                $notification = $this->notifications()->tokenExpiring($account, $token->expires_at);

                if ($notification !== null) {
                    $notified[] = $account;
                }
            });

        return $notified;
    }

    /**
     * Write a refreshed token set.
     *
     * The previous refresh token is preserved unless the provider sent a new
     * one: rotating-refresh-token providers invalidate the old value on use, and
     * providers that do not rotate simply omit it.
     */
    private function persist(SocialAccountToken $token, TokenSet $fresh): void
    {
        $token->forceFill([
            'access_token' => $fresh->accessToken,
            'refresh_token' => $fresh->refreshToken ?? $token->refresh_token,
            'id_token' => $fresh->idToken ?? $token->id_token,
            'token_type' => $fresh->tokenType,
            'expires_at' => $this->expiryFor($fresh),
            'scope' => $fresh->scopeList() === [] ? $token->scope : implode(' ', $fresh->scopeList()),
            'metadata' => array_merge((array) $token->metadata, $fresh->metadata, [
                'refreshed_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function expiryFor(TokenSet $fresh): Carbon
    {
        if ($fresh->expiresAt !== null) {
            return Carbon::instance($fresh->expiresAt);
        }

        if ($fresh->expiresIn !== null) {
            return now()->addSeconds(max(0, $fresh->expiresIn));
        }

        return now()->addDays(60);
    }

    private function markRevoked(SocialAccount $account, string $reason): void
    {
        $account->markStatus(SocialAccountStatus::Revoked, $reason);

        $this->notifications()->tokenRevoked($account, $reason, 'refresh-revoked:'.$account->getKey());

        SocialAccountTokenInvalidated::dispatch($account, $reason, true);

        Log::warning('SocialHub token refresh: access was revoked; the account needs a reconnect.', [
            'social_account_id' => $account->getKey(),
            'provider' => $account->provider?->value,
        ]);
    }

    private function markExpired(SocialAccount $account, string $reason): void
    {
        $account->markStatus(SocialAccountStatus::Expired, $reason);

        $this->notifications()->tokenExpired($account, $reason, 'refresh-expired:'.$account->getKey());

        SocialAccountTokenInvalidated::dispatch($account, $reason, false);

        Log::warning('SocialHub token refresh: the credentials could not be renewed.', [
            'social_account_id' => $account->getKey(),
            'provider' => $account->provider?->value,
        ]);
    }

    private function providerFor(SocialAccount $account): ?\App\Services\Social\Contracts\SocialProviderInterface
    {
        try {
            return $this->registry()->get((string) $account->provider->value);
        } catch (Throwable) {
            return null;
        }
    }

    private function registry(): SocialProviderRegistry
    {
        return app(SocialProviderRegistry::class);
    }

    private function notifications(): NotificationService
    {
        return app(NotificationService::class);
    }
}
