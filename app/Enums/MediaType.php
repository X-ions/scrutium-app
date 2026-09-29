<?php

namespace App\Enums;

enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Gif = 'gif';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Video',
            self::Audio => 'Audio',
            self::Gif => 'GIF',
            self::Document => 'Document',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Image => 'bg-blue-light-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
            self::Video => 'bg-theme-purple-500/10 text-theme-purple-500 dark:bg-theme-purple-500/15 dark:text-theme-purple-500',
            self::Audio => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::Gif => 'bg-theme-pink-500/10 text-theme-pink-500 dark:bg-theme-pink-500/15 dark:text-theme-pink-500',
            self::Document => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
        };
    }

    public static function fromMimeType(?string $mimeType): self
    {
        $mimeType = strtolower(trim((string) $mimeType));

        return match (true) {
            $mimeType === 'image/gif' => self::Gif,
            str_starts_with($mimeType, 'image/') => self::Image,
            str_starts_with($mimeType, 'video/') => self::Video,
            str_starts_with($mimeType, 'audio/') => self::Audio,
            default => self::Document,
        };
    }

    /**
     * Rendered inline as a thumbnail or a playable preview.
     */
    public function isVisual(): bool
    {
        return in_array($this, [self::Image, self::Video, self::Gif], true);
    }

    public function isPlayable(): bool
    {
        return in_array($this, [self::Video, self::Audio, self::Gif], true);
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
