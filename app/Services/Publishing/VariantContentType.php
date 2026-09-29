<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\MediaType;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Services\Social\Data\PublishPayload;

/**
 * Derives the content type of a variant from what it actually carries.
 *
 * The variant stores provider media handles, not media rows, so the media type
 * is read from the pivot when a `MediaAsset` is attached and inferred from the
 * handle otherwise. The capability gate reads this to decide which provider
 * flag to assert — guessing "image" for a video is exactly the mistake that
 * turns a supported post into a silent failure.
 */
final class VariantContentType
{
    /**
     * @return list<string>
     */
    public static function fromVariant(PostVariant $variant): string
    {
        $explicit = self::explicitType($variant);

        if ($explicit !== null) {
            return $explicit;
        }

        $mediaIds = self::mediaIds($variant);

        if (count($mediaIds) > 1) {
            return PublishPayload::TYPE_CAROUSEL;
        }

        if ($mediaIds === []) {
            return PublishPayload::TYPE_TEXT;
        }

        $media = self::firstMedia($variant);

        if ($media instanceof MediaAsset) {
            return match ($media->mediaType()) {
                MediaType::Video => PublishPayload::TYPE_VIDEO,
                MediaType::Gif => PublishPayload::TYPE_IMAGE,
                default => PublishPayload::TYPE_IMAGE,
            };
        }

        return PublishPayload::TYPE_IMAGE;
    }

    /**
     * Which capability flag a content type is gated on.
     */
    public static function capabilityFor(string $contentType): string
    {
        return match ($contentType) {
            PublishPayload::TYPE_IMAGE => 'imagePublishing',
            PublishPayload::TYPE_VIDEO => 'videoPublishing',
            PublishPayload::TYPE_CAROUSEL => 'carouselPublishing',
            PublishPayload::TYPE_LINK => 'linkPosts',
            PublishPayload::TYPE_STORY => 'stories',
            PublishPayload::TYPE_REEL => 'reels',
            PublishPayload::TYPE_TEXT => 'textPublishing',
            default => 'publishing',
        };
    }

    /**
     * `platform_specific` may pin the type when the composer knows better than
     * the media rows do, e.g. a Facebook reel rendered from a video asset.
     */
    private static function explicitType(PostVariant $variant): ?string
    {
        $specific = $variant->getAttribute('platform_specific');
        $type = is_array($specific) ? ($specific['content_type'] ?? null) : null;

        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        return strtolower(trim($type));
    }

    /**
     * @return list<string>
     */
    private static function mediaIds(PostVariant $variant): array
    {
        $ids = $variant->getAttribute('media_ids');

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            $ids,
        ), static fn (string $id): bool => $id !== ''));
    }

    /**
     * The pivot is only consulted when the caller already loaded it: reading
     * it lazily would turn a content-type decision into a database round trip
     * on a path that runs before every publish.
     */
    private static function firstMedia(PostVariant $variant): ?MediaAsset
    {
        if (! $variant->relationLoaded('media')) {
            return null;
        }

        $media = $variant->getRelation('media');

        return $media instanceof \Illuminate\Support\Collection ? $media->first() : null;
    }
}
