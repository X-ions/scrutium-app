<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Info = 'info';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Info => 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400',
            self::Low => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
            self::Medium => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::High => 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-500',
            self::Critical => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
        };
    }

    /**
     * Severities that should page an operator rather than sit in the feed.
     *
     * @return list<self>
     */
    public static function escalation(): array
    {
        return [self::High, self::Critical];
    }

    public function requiresEscalation(): bool
    {
        return in_array($this, self::escalation(), true);
    }

    /**
     * Sort weight so higher severities float to the top of the feed.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 5,
            self::High => 4,
            self::Medium => 3,
            self::Low => 2,
            self::Info => 1,
        };
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
