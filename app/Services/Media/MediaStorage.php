<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * Where media bytes live.
 *
 * Every asset is written to a private, non-web-servable disk. The disk is
 * recorded per asset, so changing `SOCIALHUB_MEDIA_DISK` later never orphans
 * files that were already written somewhere else.
 */
class MediaStorage
{
    /**
     * Disks whose contents are served straight off the web server. Media must
     * not go here in production: an uploaded file would become retrievable by
     * anyone who can guess or read its URL, bypassing every access check.
     */
    private const WEB_SERVABLE_DISKS = ['public'];

    public const ROOT = 'socialhub/media';

    public const THUMBNAIL_ROOT = 'socialhub/thumbnails';

    public function __construct(
        protected readonly FilesystemFactory $filesystem,
    ) {}

    public function defaultDisk(): string
    {
        return (string) config('socialhub.media.disk', 'local');
    }

    public function disk(?string $name = null): Filesystem
    {
        $name ??= $this->defaultDisk();

        if ($this->isForbidden($name)) {
            throw new RuntimeException(sprintf(
                'Refusing to use the web-servable disk "%s" for user uploads. Point SOCIALHUB_MEDIA_DISK at S3, R2 or another private disk.',
                $name,
            ));
        }

        return $this->filesystem->disk($name);
    }

    public function isForbidden(?string $name): bool
    {
        return app()->environment('production')
            && $name !== null
            && in_array($name, self::WEB_SERVABLE_DISKS, true);
    }

    /**
     * The path an asset's bytes are written to. The client filename is never
     * used, so a crafted name cannot traverse directories or overwrite a
     * sibling asset.
     */
    public function pathFor(int|string|null $tenantId, string $filename, bool $thumbnail = false): string
    {
        $root = $thumbnail ? self::THUMBNAIL_ROOT : self::ROOT;

        return $tenantId === null
            ? $root.'/'.$filename
            : $root.'/'.$tenantId.'/'.$filename;
    }

    public function get(string $disk, string $path): ?string
    {
        $contents = $this->filesystem->disk($disk)->get($path);

        return is_string($contents) ? $contents : null;
    }

    public function put(string $disk, string $path, string $bytes): bool
    {
        return (bool) $this->filesystem->disk($disk)->put($path, $bytes);
    }

    public function exists(string $disk, string $path): bool
    {
        return $this->filesystem->disk($disk)->exists($path);
    }

    public function delete(string $disk, string $path): bool
    {
        return (bool) $this->filesystem->disk($disk)->delete($path);
    }

    public function url(string $disk, string $path): ?string
    {
        $adapter = $this->filesystem->disk($disk);

        if (method_exists($adapter, 'temporaryUrl') && str_contains($disk, 's3')) {
            return $adapter->temporaryUrl($path, now()->addMinutes(15));
        }

        try {
            return $adapter->url($path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function absolutePath(string $disk, string $path): string
    {
        return $this->filesystem->disk($disk)->path($path);
    }
}
