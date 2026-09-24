<?php

namespace App\Enums;

enum DeliverableType: string
{
    case Post = 'post';
    case Story = 'story';
    case Reel = 'reel';
    case Video = 'video';
    case Blog = 'blog';
    case Podcast = 'podcast';

    public function label(): string
    {
        return match ($this) {
            self::Post => 'Post',
            self::Story => 'Story',
            self::Reel => 'Reel',
            self::Video => 'Video',
            self::Blog => 'Blog',
            self::Podcast => 'Podcast',
        };
    }

    /**
     * Story-formats are time-limited and verified faster than evergreen units.
     */
    public function verificationWindowHours(): int
    {
        return match ($this) {
            self::Story => 24,
            self::Post => 72,
            self::Reel => 72,
            self::Video => 168,
            self::Blog => 168,
            self::Podcast => 168,
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
