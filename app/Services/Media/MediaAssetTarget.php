<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * What the thumbnailer needs to know about an asset, without pulling the model
 * into the storage layer.
 */
final readonly class MediaAssetTarget
{
    public function __construct(
        public int $id,
        public string $disk,
        public string $path,
        public string $mimeType,
        public string $thumbnailName,
        public int|string|null $tenantId,
    ) {}
}
