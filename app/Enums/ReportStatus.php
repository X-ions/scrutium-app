<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Draft = 'draft';
    case Frozen = 'frozen';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Frozen => 'Frozen',
            self::Published => 'Published',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
            self::Frozen => 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400',
            self::Published => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
        };
    }

    /**
     * Frozen and published reports are immutable snapshots.
     */
    public function isImmutable(): bool
    {
        return $this !== self::Draft;
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
