<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\Data\UserFacingError;

/**
 * The platform rejected the token permanently (invalid_grant, revoked access,
 * password change). Automatic refresh loops are not attempted for this.
 */
final class TokenRevokedException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $provider,
        ?UserFacingError $userFacingError = null,
        array $context = [],
    ) {
        parent::__construct(
            sprintf('The access token for provider "%s" was revoked by the platform.', $provider),
            $userFacingError ?? UserFacingError::make(
                'token_revoked',
                'Access to this account was withdrawn on the network. Reconnect it to continue.',
                sprintf('Provider "%s" reported the token as invalid or revoked.', $provider),
                false,
                'Reconnect the account from the Social Accounts page.',
                ['provider' => $provider],
            ),
            null,
            ['provider' => $provider] + $context,
        );
    }
}
