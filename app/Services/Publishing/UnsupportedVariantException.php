<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Services\Social\Data\UserFacingError;
use RuntimeException;

/**
 * Raised when a variant cannot be re-queued at all: the network does not
 * support the content, or the account is no longer usable. The variant has
 * already been marked failed with a readable message by the time this is thrown.
 */
final class UnsupportedVariantException extends RuntimeException
{
    public function __construct(public readonly UserFacingError $error)
    {
        parent::__construct($error->userMessage);
    }
}
