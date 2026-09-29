<?php

namespace App\Enums;

enum ScheduledPostStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Published => 'Published',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
            self::Queued => 'bg-blue-light-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
            self::Processing => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::Published => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Failed => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Cancelled => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-white/60',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Published, self::Failed, self::Cancelled], true);
    }

    /**
     * A claimed job that must not be picked up by another worker.
     */
    public function isLocked(): bool
    {
        return $this === self::Processing;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionsFor($this), true);
    }

    /**
     * @return list<self>
     */
    public static function transitionsFor(self $status): array
    {
        return match ($status) {
            self::Pending => [self::Queued, self::Cancelled],
            self::Queued => [self::Processing, self::Cancelled, self::Failed],
            self::Processing => [self::Published, self::Failed, self::Queued, self::Cancelled],
            self::Failed => [self::Queued, self::Cancelled],
            self::Published, self::Cancelled => [],
        };
    }

    /**
     * @return list<string>
     */
    public function allowedTargets(): array
    {
        return array_map(fn (self $status) => $status->value, self::transitionsFor($this));
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
