<?php

namespace App\Enums;

enum SocialAccountStatus: string
{
    case Connected = 'connected';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Error = 'error';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Expired => 'Token expired',
            self::Revoked => 'Access revoked',
            self::Error => 'Error',
            self::Disconnected => 'Disconnected',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Connected => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Expired => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::Revoked => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Error => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Disconnected => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
        };
    }

    /**
     * Account can be used to publish or sync without reauthorisation.
     */
    public function isActive(): bool
    {
        return $this === self::Connected;
    }

    /**
     * The account can never return to Connected without a fresh authorisation.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Revoked, self::Disconnected], true);
    }

    /**
     * Whether the token behind the account will need renewing.
     */
    public function requiresReauthorization(): bool
    {
        return in_array($this, [self::Expired, self::Revoked, self::Error], true);
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
            self::Connected => [self::Expired, self::Revoked, self::Error, self::Disconnected],
            self::Expired => [self::Connected, self::Revoked, self::Error, self::Disconnected],
            self::Error => [self::Connected, self::Expired, self::Revoked, self::Disconnected],
            self::Revoked, self::Disconnected => [],
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
