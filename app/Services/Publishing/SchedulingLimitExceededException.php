<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Services\Social\Data\UserFacingError;
use RuntimeException;

/**
 * Raised when a workspace has no scheduled-post slots left.
 *
 * Carries a {@see UserFacingError} so the controller can render the same
 * message the publishing path would, instead of translating an exception code.
 */
final class SchedulingLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly ?UserFacingError $userFacingError,
        public readonly int $limit,
        public readonly int $used,
    ) {
        parent::__construct($userFacingError?->userMessage ?? 'Scheduled post limit reached.');
    }
}
