<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\Data\UserFacingError;
use Throwable;

/**
 * Raised before any network call when a provider's credentials are missing from
 * config. The UI uses this to hide publish affordances rather than fake them.
 */
final class ProviderNotConfiguredException extends SocialProviderException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
        array $context = [],
    ) {
        parent::__construct(
            sprintf(
                'Provider "%s" is not configured: %s',
                $provider,
                $reason ?? 'required credentials are missing',
            ),
            UserFacingError::make(
                'provider_not_configured',
                sprintf('%s is not connected to this workspace yet.', $provider),
                sprintf('Provider "%s" is missing: %s', $provider, $reason ?? 'required credentials'),
                false,
                'An administrator must add the provider credentials before it can be used.',
                ['provider' => $provider],
            ),
            $previous,
            ['provider' => $provider] + $context,
        );
    }
}
