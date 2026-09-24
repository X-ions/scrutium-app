<?php

namespace App\Enums;

enum AlertStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::Resolved => 'Resolved',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Open => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Acknowledged => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::Resolved => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
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
