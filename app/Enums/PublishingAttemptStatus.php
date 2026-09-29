<?php

namespace App\Enums;

enum PublishingAttemptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
    case RateLimited = 'rate_limited';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::RateLimited => 'Rate limited',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
            self::Processing => 'bg-blue-light-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
            self::Success => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Failed => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::RateLimited => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Failed], true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::Success;
    }

    /**
     * The publisher may be called again after this outcome.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Failed, self::RateLimited], true);
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
            self::Pending => [self::Processing, self::Failed],
            self::Processing => [self::Success, self::Failed, self::RateLimited],
            self::RateLimited => [self::Processing, self::Failed, self::Success],
            self::Success, self::Failed => [],
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
