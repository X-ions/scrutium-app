<?php

namespace App\Enums;

enum DeliverableStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Late = 'late';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Late => 'Late',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
            self::Submitted => 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400',
            self::Approved => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Rejected => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Late => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
        };
    }

    /**
     * A deliverable counts toward attainment once approved.
     */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Statuses that still need operator attention.
     *
     * @return list<self>
     */
    public static function outstanding(): array
    {
        return [self::Pending, self::Submitted, self::Late];
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
