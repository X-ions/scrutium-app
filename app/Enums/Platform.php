<?php

namespace App\Enums;

enum Platform: string
{
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case Facebook = 'facebook';
    case Pinterest = 'pinterest';
    case Twitch = 'twitch';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::YouTube => 'YouTube',
            self::X => 'X',
            self::LinkedIn => 'LinkedIn',
            self::Facebook => 'Facebook',
            self::Pinterest => 'Pinterest',
            self::Twitch => 'Twitch',
        };
    }

    /**
     * Default cost-per-engagement benchmark used by the scoring engine.
     */
    public function benchmarkCpe(): float
    {
        return match ($this) {
            self::Instagram => 0.35,
            self::TikTok => 0.18,
            self::YouTube => 0.55,
            self::X => 0.28,
            self::LinkedIn => 1.20,
            self::Facebook => 0.24,
            self::Pinterest => 0.31,
            self::Twitch => 0.90,
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
