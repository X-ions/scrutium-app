<?php

namespace App\Enums;

enum CampaignStage: string
{
    case Brief = 'brief';
    case Casting = 'casting';
    case Live = 'live';
    case Reconcile = 'reconcile';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Brief => 'Brief',
            self::Casting => 'Casting',
            self::Live => 'Live',
            self::Reconcile => 'Reconcile',
            self::Completed => 'Completed',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Brief => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400',
            self::Casting => 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400',
            self::Live => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Reconcile => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::Completed => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
        };
    }

    /**
     * Ordered lifecycle used by the overview data-lifecycle widget.
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return [self::Brief, self::Casting, self::Live, self::Reconcile];
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
