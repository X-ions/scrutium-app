<?php

declare(strict_types=1);

namespace App\Services\Engagement;

/**
 * The outcome of one account's comment sync.
 *
 * Returned rather than thrown so a caller can record what happened per account
 * without a try/catch per account, and so a partial success (some comments
 * upserted, then the provider failed) is representable.
 */
final readonly class CommentSyncResult
{
    /**
     * @param  list<int>  $created
     * @param  list<int>  $updated
     * @param  list<string>  $skipped
     */
    public function __construct(
        public int $socialAccountId,
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $markedHidden = 0,
        public int $markedDeleted = 0,
        public array $skipped = [],
        public ?string $failure = null,
        public ?string $failureCode = null,
    ) {}

    public function failed(): bool
    {
        return $this->failure !== null;
    }

    public function written(): int
    {
        return $this->created + $this->updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'social_account_id' => $this->socialAccountId,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'marked_hidden' => $this->markedHidden,
            'marked_deleted' => $this->markedDeleted,
            'skipped' => $this->skipped,
            'written' => $this->written(),
            'failed' => $this->failed(),
            'failure' => $this->failure,
            'failure_code' => $this->failureCode,
        ];
    }
}
