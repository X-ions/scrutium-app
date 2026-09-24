<?php

namespace App\Enums;

enum InfluencerTier: string
{
    case Nano = 'nano';
    case Micro = 'micro';
    case Macro = 'macro';
    case Mega = 'mega';

    public function label(): string
    {
        return match ($this) {
            self::Nano => 'Nano',
            self::Micro => 'Micro',
            self::Macro => 'Macro',
            self::Mega => 'Mega',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Nano => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
            self::Micro => 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400',
            self::Macro => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400',
            self::Mega => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
        };
    }

    /**
     * Follower band that maps to each tier.
     *
     * @return array{0: int, 1: int|null}
     */
    public function followerRange(): array
    {
        return match ($this) {
            self::Nano => [1_000, 10_000],
            self::Micro => [10_001, 100_000],
            self::Macro => [100_001, 1_000_000],
            self::Mega => [1_000_001, null],
        };
    }

    /**
     * Derive a tier from a follower count.
     */
    public static function fromFollowers(int $followers): self
    {
        return match (true) {
            $followers >= 1_000_001 => self::Mega,
            $followers >= 100_001 => self::Macro,
            $followers >= 10_001 => self::Micro,
            default => self::Nano,
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
