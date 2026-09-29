<?php

namespace App\Enums;

enum CommentSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Sent = 'sent';
    case Failed = 'failed';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Synced => 'Synced',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Deleted => 'Deleted',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
            self::Synced, self::Sent => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Failed => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Deleted => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-white/60',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Synced, self::Sent, self::Failed, self::Deleted], true);
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
            self::Pending => [self::Synced, self::Sent, self::Failed, self::Deleted],
            self::Failed => [self::Pending, self::Synced, self::Sent, self::Deleted],
            self::Synced, self::Sent => [self::Deleted],
            self::Deleted => [],
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
