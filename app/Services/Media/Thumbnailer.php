<?php

declare(strict_types=1);

namespace App\Services\Media;

use RuntimeException;

/**
 * Produces a small preview image for an asset.
 *
 * Resizing needs a raster library, and this application may run without GD or
 * Imagick (a slim PHP-FPM image, a serverless build that omitted the extension).
 * So the thumbnail is produced when a driver is present and the asset is still
 * perfectly usable when it is not: the row keeps `thumbnail_path = null` and the
 * library falls back to serving the original. That is recorded honestly rather
 * than by silently writing a byte-for-byte copy of the original under a
 * thumbnail name, which would be a thumbnail that costs full storage and full
 * bandwidth.
 */
class Thumbnailer
{
    public const MAX_EDGE = 480;

    private readonly MediaStorage $storage;

    public function __construct(
        ?MediaStorage $storage = null,
    ) {
        $this->storage = $storage ?? new MediaStorage(app('filesystem'));
    }

    public function isAvailable(): bool
    {
        return $this->driver() !== null;
    }

    public function driver(): ?string
    {
        if (function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled')) {
            return 'gd';
        }

        if (class_exists(\Imagick::class)) {
            return 'imagick';
        }

        return null;
    }

    /**
     * Write a thumbnail next to the asset and return its path, or null when the
     * format is not raster-image or no driver is installed.
     */
    public function make(MediaAssetTarget $target): ?string
    {
        $source = $this->storage->get($target->disk, $target->path);

        if ($source === null || $source === '') {
            return null;
        }

        $resized = $this->driver() === 'gd'
            ? $this->resizeWithGd($source, $target->mimeType)
            : $this->resizeWithImagick($source);

        if ($resized === null) {
            return null;
        }

        $path = $this->storage->pathFor($target->tenantId, $target->thumbnailName, thumbnail: true);

        if (! $this->storage->put($target->disk, $path, $resized)) {
            throw new RuntimeException(sprintf('Could not write the thumbnail for media asset %d.', $target->id));
        }

        return $path;
    }

    private function resizeWithGd(string $source, string $mimeType): ?string
    {
        $image = @imagecreatefromstring($source);

        if ($image === false) {
            return null;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width <= 0 || $height <= 0) {
                return null;
            }

            $scale = min(1.0, self::MAX_EDGE / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);

            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            ob_start();
            $ok = $mimeType === 'image/png'
                ? imagepng($canvas, null, 6)
                : imagejpeg($canvas, null, 82);
            $bytes = (string) ob_get_clean();

            imagedestroy($canvas);

            return $ok ? $bytes : null;
        } finally {
            imagedestroy($image);
        }
    }

    private function resizeWithImagick(string $source): ?string
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $image = new \Imagick;
            $image->readImageBlob($source);

            if ($image->getImageWidth() === 0 || $image->getImageHeight() === 0) {
                return null;
            }

            $image->thumbnailImage(self::MAX_EDGE, self::MAX_EDGE, true);
            $image->setImageCompressionQuality(82);

            return $image->getImageBlob();
        } catch (\Throwable) {
            return null;
        }
    }
}
