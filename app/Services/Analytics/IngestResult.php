<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * What one ingestion call actually did.
 *
 * The counts are deliberately split so a caller can tell "the provider reported
 * nothing" from "we wrote it" from "we refused it because it is out of
 * retention", and so unattributed readings are visible rather than blended in.
 */
final readonly class IngestResult
{
    /**
     * @param  list<string>  $unmatchedPostIds
     * @param  list<string>  $unmappedKeys
     */
    public function __construct(
        public int $inserted = 0,
        public int $updated = 0,
        public int $skippedOutOfRetention = 0,
        public int $skippedDuplicate = 0,
        public array $unmatchedPostIds = [],
        public array $unmappedKeys = [],
    ) {}

    public function written(): int
    {
        return $this->inserted + $this->updated;
    }

    public function hasUnattributedPosts(): bool
    {
        return $this->unmatchedPostIds !== [];
    }

    public function merge(self $other): self
    {
        return new self(
            inserted: $this->inserted + $other->inserted,
            updated: $this->updated + $other->updated,
            skippedOutOfRetention: $this->skippedOutOfRetention + $other->skippedOutOfRetention,
            skippedDuplicate: $this->skippedDuplicate + $other->skippedDuplicate,
            unmatchedPostIds: array_values(array_unique([...$this->unmatchedPostIds, ...$other->unmatchedPostIds])),
            unmappedKeys: array_values(array_unique([...$this->unmappedKeys, ...$other->unmappedKeys])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'skipped_out_of_retention' => $this->skippedOutOfRetention,
            'skipped_duplicate' => $this->skippedDuplicate,
            'unmatched_post_ids' => $this->unmatchedPostIds,
            'unmapped_keys' => $this->unmappedKeys,
        ];
    }
}
