<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\Data\UserFacingError;

/**
 * The stored access token has passed its expiry and no refresh succeeded yet.
 * The refresh scheduler owns recovery; callers must not retry blindly.
 */
final class TokenExpiredException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $provider,
        ?UserFacingError $userFacingError = null,
        array $context = [],
    ) {
        parent::__construct(
            sprintf('The access token for provider "%s" has expired.', $provider),
            $userFacingError ?? UserFacingError::make(
                'token_expired',
                'This account needs to be reconnected before it can be used.',
                sprintf('Access token expiry reached for provider "%s".', $provider),
                false,
                'Reconnect the account from the Social Accounts page.',
                ['provider' => $provider],
            ),
            null,
            ['provider' => $provider] + $context,
        );
    }
}
