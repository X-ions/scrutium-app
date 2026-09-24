<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Analyst = 'analyst';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Analyst => 'Analyst',
            self::Viewer => 'Viewer',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Owner => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400',
            self::Admin => 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400',
            self::Manager => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Analyst => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::Viewer => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
        };
    }

    /**
     * Roles allowed to administer tenant settings and members.
     *
     * @return list<self>
     */
    public static function administrative(): array
    {
        return [self::Owner, self::Admin];
    }

    public function canManageWorkspace(): bool
    {
        return in_array($this, self::administrative(), true);
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
