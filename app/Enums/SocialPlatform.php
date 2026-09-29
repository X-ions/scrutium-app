<?php

namespace App\Enums;

enum SocialPlatform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case Pinterest = 'pinterest';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::YouTube => 'YouTube',
            self::TikTok => 'TikTok',
            self::X => 'X',
            self::LinkedIn => 'LinkedIn',
            self::Pinterest => 'Pinterest',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Facebook => 'bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-500',
            self::Instagram => 'bg-theme-pink-500/10 text-theme-pink-500 dark:bg-theme-pink-500/15 dark:text-theme-pink-500',
            self::YouTube => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::TikTok => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
            self::X => 'bg-gray-100 text-gray-800 dark:bg-white/5 dark:text-white/90',
            self::LinkedIn => 'bg-blue-light-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
            self::Pinterest => 'bg-error-50 text-error-500 dark:bg-error-500/15 dark:text-error-500',
        };
    }

    /**
     * Account kinds the provider accepts during authorisation.
     *
     * @return list<string>
     */
    public function accountTypes(): array
    {
        return match ($this) {
            self::Facebook => ['page', 'personal'],
            self::Instagram => ['personal', 'business', 'creator'],
            self::YouTube => ['channel'],
            self::TikTok => ['personal', 'business', 'creator'],
            self::X => ['personal', 'business', 'creator'],
            self::LinkedIn => ['personal', 'business', 'creator'],
            self::Pinterest => ['personal', 'business'],
        };
    }

    public function supportsNativeScheduling(): bool
    {
        return in_array($this, [self::Facebook, self::Instagram, self::YouTube, self::X, self::LinkedIn], true);
    }

    public function supportsNativeHashtags(): bool
    {
        return ! in_array($this, [self::LinkedIn], true);
    }

    public function supportsNativeMentions(): bool
    {
        return in_array($this, [self::Facebook, self::Instagram, self::X, self::LinkedIn, self::TikTok], true);
    }

    public function maxCaptionLength(): int
    {
        return match ($this) {
            self::Facebook => 63_206,
            self::Instagram => 2_200,
            self::YouTube => 5_000,
            self::TikTok => 2_200,
            self::X => 280,
            self::LinkedIn => 3_000,
            self::Pinterest => 500,
        };
    }

    public function maxMediaAttachments(): int
    {
        return match ($this) {
            self::Facebook => 10,
            self::Instagram => 10,
            self::YouTube => 1,
            self::TikTok => 10,
            self::X => 4,
            self::LinkedIn => 9,
            self::Pinterest => 10,
        };
    }

    public function isSupported(): bool
    {
        return in_array($this, self::cases(), true);
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
