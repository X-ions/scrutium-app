<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\Data\UserFacingError;
use RuntimeException;
use Throwable;

/**
 * Base class for every failure raised by the social provider layer.
 *
 * Exception messages are safe to log: no accessor tokens, client secrets or
 * PKCE verifiers may ever be interpolated into them.
 */
class SocialProviderException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        public readonly ?UserFacingError $userFacingError = null,
        ?Throwable $previous = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(
        string $message,
        ?UserFacingError $userFacingError = null,
        array $context = [],
    ): static {
        return new static($message, $userFacingError, null, $context);
    }

    public function userMessage(): string
    {
        return $this->userFacingError?->userMessage ?? $this->getMessage();
    }
}
