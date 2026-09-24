<?php

namespace App\Enums;

enum IntegrationStatus: string
{
    case Connected = 'connected';
    case Degraded = 'degraded';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Degraded => 'Degraded',
            self::Disconnected => 'Disconnected',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Connected => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Degraded => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::Disconnected => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
        };
    }

    /**
     * Provider is reachable and usable for auto-verification.
     */
    public function isHealthy(): bool
    {
        return $this === self::Connected;
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
