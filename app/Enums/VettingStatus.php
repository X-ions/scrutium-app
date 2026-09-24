<?php

namespace App\Enums;

enum VettingStatus: string
{
    case Sourced = 'sourced';
    case Pending = 'pending';
    case Vetted = 'vetted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Sourced => 'Sourced',
            self::Pending => 'Pending review',
            self::Vetted => 'Vetted',
            self::Rejected => 'Rejected',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Sourced => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
            self::Pending => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::Vetted => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Rejected => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
        };
    }

    /**
     * Only vetted influencers can be staffed onto a campaign roster.
     */
    public function isBookable(): bool
    {
        return $this === self::Vetted;
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
