<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\Data\UserFacingError;
use Throwable;

/**
 * OAuth could not be completed: invalid state, expired state row, provider
 * denied the request, or the token exchange failed.
 */
class AuthenticationException extends SocialProviderException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'The social authorization could not be completed.',
        ?UserFacingError $userFacingError = null,
        ?Throwable $previous = null,
        array $context = [],
    ) {
        parent::__construct($message, $userFacingError, $previous, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function invalidState(string $provider, ?Throwable $previous = null): static
    {
        return new static(
            sprintf('The authorization state for "%s" is missing, expired, or already used.', $provider),
            UserFacingError::make(
                'oauth_invalid_state',
                'This authorization link is no longer valid. Start the connection again from Social Accounts.',
                sprintf('OAuth state lookup failed for provider "%s".', $provider),
                false,
                'Reconnect the account from the Social Accounts page.',
                ['provider' => $provider],
            ),
            $previous,
            ['provider' => $provider],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function stateStoreUnavailable(string $provider, ?Throwable $previous = null): static
    {
        return new static(
            sprintf('The OAuth state store is unavailable, so the "%s" connection cannot be started safely.', $provider),
            UserFacingError::make(
                'oauth_state_store_unavailable',
                'Connecting a social account is temporarily unavailable. Try again shortly.',
                sprintf('No OAuth state store is bound for provider "%s".', $provider),
                true,
                'Retry the connection; if it persists, check that the oauth_states table exists and is migrated.',
                ['provider' => $provider],
            ),
            $previous,
            ['provider' => $provider],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function denied(string $provider, ?string $description = null, ?Throwable $previous = null): static
    {
        return new static(
            $description !== null && $description !== ''
                ? sprintf('Provider "%s" denied the authorization: %s', $provider, $description)
                : sprintf('Provider "%s" denied the authorization.', $provider),
            UserFacingError::make(
                'oauth_denied',
                'The authorization was cancelled. Nothing has been connected yet.',
                sprintf('Provider "%s" returned an authorization error.', $provider),
                false,
                'Start the connection again and approve the requested permissions.',
                ['provider' => $provider],
            ),
            $previous,
            ['provider' => $provider],
        );
    }
}
