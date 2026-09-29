<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\CommentSyncStatus;
use App\Enums\SocialPlatform;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

/**
 * The filter set the unified inbox honours.
 *
 * A value object rather than loose request parameters, so a filter cannot be
 * accepted by a controller and then quietly dropped by the query: every read in
 * {@see CommentQueryService} goes through the one `scoped()` method, which
 * applies all of them.
 */
final readonly class CommentFilters
{
    /**
     * @param  list<SocialPlatform>  $platforms
     * @param  list<int>  $accountIds
     * @param  list<int>  $postVariantIds
     * @param  list<CommentSyncStatus>  $statuses
     */
    public function __construct(
        public ?DateTimeInterface $from = null,
        public ?DateTimeInterface $to = null,
        public array $platforms = [],
        public array $accountIds = [],
        public array $postVariantIds = [],
        public array $statuses = [],
        public ?string $search = null,
        public ?bool $unreadOnly = null,
        public ?bool $handledOnly = null,
        public bool $includeHidden = false,
        public bool $includeDeleted = false,
        public string $sort = 'newest',
    ) {
        if ($this->from !== null && $this->to !== null && $this->from->getTimestamp() > $this->to->getTimestamp()) {
            throw new InvalidArgumentException('CommentFilters::from must not be after ::to.');
        }
    }

    /**
     * @param  array<int, mixed>  $overrides
     */
    public static function make(array $overrides = []): self
    {
        return new self(
            from: self::date($overrides['from'] ?? $overrides['date_from'] ?? null),
            to: self::date($overrides['to'] ?? $overrides['date_to'] ?? null),
            platforms: self::platforms((array) ($overrides['platforms'] ?? $overrides['platform'] ?? [])),
            accountIds: self::ints((array) ($overrides['account_ids'] ?? $overrides['account_id'] ?? [])),
            postVariantIds: self::ints((array) ($overrides['post_variant_ids'] ?? $overrides['post_variant_id'] ?? [])),
            statuses: self::statuses((array) ($overrides['statuses'] ?? $overrides['status'] ?? [])),
            search: self::text($overrides['search'] ?? $overrides['q'] ?? null, 120),
            unreadOnly: self::bool($overrides['unread'] ?? $overrides['unread_only'] ?? null),
            handledOnly: self::bool($overrides['handled'] ?? $overrides['handled_only'] ?? null),
            includeHidden: (bool) self::bool($overrides['include_hidden'] ?? false),
            includeDeleted: (bool) self::bool($overrides['include_deleted'] ?? false),
            sort: in_array((string) ($overrides['sort'] ?? 'newest'), ['newest', 'oldest', 'most_liked'], true)
                ? (string) ($overrides['sort'] ?? 'newest')
                : 'newest',
        );
    }

    public function has(): bool
    {
        return $this->from !== null
            || $this->to !== null
            || $this->platforms !== []
            || $this->accountIds !== []
            || $this->postVariantIds !== []
            || $this->statuses !== []
            || $this->search !== null
            || $this->unreadOnly === true
            || $this->handledOnly === true;
    }

    /**
     * A comment is "handled" once a reply of ours exists for it and that reply
     * was delivered. A failed or still-pending reply leaves it in the inbox,
     * because the workspace still owes the author an answer.
     */
    public function requiresRepliedSubquery(): bool
    {
        return $this->handledOnly !== null || $this->unreadOnly === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'from' => $this->from?->format(DateTimeInterface::ATOM),
            'to' => $this->to?->format(DateTimeInterface::ATOM),
            'platforms' => array_map(static fn (SocialPlatform $p): string => $p->value, $this->platforms),
            'account_ids' => $this->accountIds,
            'post_variant_ids' => $this->postVariantIds,
            'statuses' => array_map(static fn (CommentSyncStatus $s): string => $s->value, $this->statuses),
            'search' => $this->search,
            'unread' => $this->unreadOnly,
            'handled' => $this->handledOnly,
            'include_hidden' => $this->includeHidden,
            'include_deleted' => $this->includeDeleted,
            'sort' => $this->sort,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<SocialPlatform>
     */
    private static function platforms(array $values): array
    {
        $platforms = [];

        foreach ($values as $value) {
            if ($value instanceof SocialPlatform) {
                $platform = $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $platform = SocialPlatform::tryFrom(strtolower(trim($value)));
            } else {
                continue;
            }

            if ($platform !== null && ! in_array($platform, $platforms, true)) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<CommentSyncStatus>
     */
    private static function statuses(array $values): array
    {
        $statuses = [];

        foreach ($values as $value) {
            if ($value instanceof CommentSyncStatus) {
                $status = $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $status = CommentSyncStatus::tryFrom(strtolower(trim($value)));
            } else {
                continue;
            }

            if ($status !== null && ! in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function date(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (Throwable) {
            return null;
        }
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function bool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
