<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\Data\UserFacingError;
use Throwable;

/**
 * A webhook request failed HMAC verification, carried an out-of-tolerance
 * timestamp, or was structurally unparseable. Never logged with its raw body.
 */
final class WebhookSignatureException extends SocialProviderException
{
    public const REASON_SIGNATURE = 'signature_mismatch';

    public const REASON_TIMESTAMP = 'timestamp_out_of_tolerance';

    public const REASON_MISSING_SIGNATURE = 'missing_signature';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $reason = self::REASON_SIGNATURE,
        ?Throwable $previous = null,
        array $context = [],
    ) {
        parent::__construct(
            sprintf('Webhook from provider "%s" failed verification (%s).', $provider, $reason),
            UserFacingError::make(
                'webhook_signature_invalid',
                'A webhook from the social network could not be verified and was rejected.',
                sprintf('Webhook verification failed for provider "%s": %s', $provider, $reason),
                false,
                'Confirm the provider app secret configured for this webhook matches the one in the provider dashboard.',
                ['provider' => $provider],
            ),
            $previous,
            ['provider' => $provider, 'reason' => $reason] + $context,
        );
    }
}
