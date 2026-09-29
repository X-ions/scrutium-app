<?php

declare(strict_types=1);

namespace App\Services\Social\OAuth;

use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Services\Engagement\NotificationService;
use App\Services\Social\Data\AuthSession;
use App\Services\Social\Data\TokenSet;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a completed OAuth handshake into a stored, connected account.
 *
 * Tokens are written through the model's encrypted casts and are never
 * returned to a caller. Re-connecting an account that already exists updates
 * the existing row in place so its post history stays attached.
 */
final class AccountConnectionService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function persist(AuthSession $session, int $tenantId, ?int $userId = null): SocialAccount
    {
        $profile = $session->profile;
        $tokens = $session->tokens;

        if ($profile === null) {
            throw new \App\Exceptions\Social\AuthenticationException(
                sprintf('Provider "%s" did not return an account profile.', $session->provider),
            );
        }

        if ($tokens === null) {
            throw new \App\Exceptions\Social\AuthenticationException(
                sprintf('Provider "%s" did not return an access token.', $session->provider),
            );
        }

        return DB::transaction(function () use ($session, $profile, $tokens, $tenantId, $userId): SocialAccount {
            $account = SocialAccount::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('provider', $session->provider)
                ->where('provider_account_id', $profile->providerAccountId)
                ->first() ?? new SocialAccount([
                    'tenant_id' => $tenantId,
                    'provider' => $session->provider,
                    'provider_account_id' => $profile->providerAccountId,
                ]);

            $account->fill([
                'provider_username' => $profile->username,
                'provider_display_name' => $profile->displayName ?? $profile->username,
                'provider_avatar_url' => $profile->avatarUrl,
                'account_type' => $profile->accountType,
                'permissions' => $profile->grantedScopes,
                'metadata' => $profile->raw,
                'status' => SocialAccountStatus::Connected,
                'last_error' => null,
                'last_synced_at' => now(),
            ]);

            if ($userId !== null && $account->user_id === null) {
                $account->user_id = $userId;
            }

            $account->save();

            $this->storeToken($account, $tokens, $session->scopes);

            return $account->fresh();
        });
    }

    private function storeToken(SocialAccount $account, TokenSet $tokens, array $scopes): void
    {
        SocialAccountToken::query()
            ->withoutGlobalScopes()
            ->where('social_account_id', $account->getKey())
            ->delete();

        SocialAccountToken::query()->create([
            'social_account_id' => $account->getKey(),
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'id_token' => $tokens->idToken,
            'token_type' => $tokens->tokenType,
            'expires_at' => $this->expiryFor($tokens),
            'scope' => implode(' ', $scopes),
            'metadata' => $tokens->metadata,
        ]);
    }

    private function expiryFor(TokenSet $tokens): ?Carbon
    {
        if ($tokens->expiresAt instanceof DateTimeInterface) {
            return Carbon::instance($tokens->expiresAt);
        }

        if ($tokens->expiresIn !== null) {
            return now()->addSeconds(max(0, $tokens->expiresIn));
        }

        return null;
    }

    /**
     * Mark an account disconnected and clear its stored credentials. Called
     * when a provider reports a revocation through a webhook or a refresh.
     */
    public function markRevoked(SocialAccount $account, string $reason): void
    {
        $account->token?->delete();
        $account->markStatus(SocialAccountStatus::Revoked, $reason);

        $this->notifications->tokenRevoked($account, $reason, 'revoked:'.$account->getKey());
    }
}
