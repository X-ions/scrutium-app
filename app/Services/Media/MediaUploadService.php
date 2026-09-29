<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Validates and ingests an upload.
 *
 * The type is decided from the file's own bytes by {@see MediaValidator}, never
 * from the client's Content-Type header or the name it arrived under, so a
 * script renamed `holiday.jpg` is rejected before it reaches storage. The
 * stored filename is a UUID; the client's name is kept only as metadata so the
 * library can be recognisable.
 */
final class MediaUploadService
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaValidator $validator,
        private readonly Thumbnailer $thumbnailer,
    ) {}

    /**
     * @param  list<string>  $tags
     */
    public function upload(
        UploadedFile $file,
        User $uploader,
        ?string $folder = null,
        array $tags = [],
        ?string $altText = null,
    ): MediaAsset {
        $absolute = $file->getRealPath();

        if ($absolute === false) {
            throw MediaUploadException::unreadable('The temporary upload path could not be resolved.');
        }

        // Throws before anything is written, so a refused upload leaves no
        // trace on the disk.
        $inspector = $this->validator->validate(
            $absolute,
            (string) $file->getClientOriginalName(),
            $file->getClientMimeType(),
            (int) $file->getSize(),
        );

        $this->assertTenantHasRoom($uploader, $inspector->byteSize);

        $disk = $this->storage->defaultDisk();
        $storedName = Str::uuid()->toString().'.'.$this->validator->storageExtensionFor($inspector);
        $path = $this->storage->pathFor($uploader->tenant_id, $storedName);

        $bytes = $this->validator->sanitizedBytes($absolute, $inspector);

        if (! $this->storage->put($disk, $path, $bytes)) {
            throw MediaUploadException::unreadable('The storage backend rejected the write.');
        }

        $thumbnailPath = $this->thumbnailer->make(new MediaAssetTarget(
            id: 0,
            disk: $disk,
            path: $path,
            mimeType: $inspector->mimeType,
            thumbnailName: $storedName,
            tenantId: $uploader->tenant_id,
        ));

        return MediaAsset::query()->create([
            'tenant_id' => $uploader->tenant_id,
            'user_id' => $uploader->getKey(),
            'filename' => $this->displayName($file),
            'stored_filename' => $storedName,
            'mime_type' => $inspector->mimeType,
            'file_size' => $inspector->byteSize,
            'width' => $inspector->width,
            'height' => $inspector->height,
            'duration' => $inspector->durationSeconds,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'alt_text' => $this->altText($altText),
            'tags' => $this->normaliseTags($tags),
            'folder' => $this->folder($folder),
            'metadata' => $this->metadata($inspector),
        ]);
    }

    /**
     * The audit trail kept alongside the asset.
     *
     * `has_gps` and `gps_stripped` are recorded so a reader can tell the
     * difference between "there was never any location data" and "location
     * data was present and removed".
     *
     * @return array<string, mixed>
     */
    private function metadata(MediaInspector $inspector): array
    {
        $exif = $inspector->exif;
        $hasGps = array_intersect_key($exif, ExifReader::gpsTags()) !== [];

        return [
            'checksum_sha256' => $inspector->checksum,
            'byte_size' => $inspector->byteSize,
            'width' => $inspector->width,
            'height' => $inspector->height,
            'duration_seconds' => $inspector->durationSeconds,
            'exif' => array_merge($exif, [
                'has_exif' => $exif !== [],
                'has_gps' => $hasGps,
            ]),
            'has_gps' => $hasGps,
            'exif_removed' => $inspector->exifRemoved,
            'gps_stripped' => $inspector->gpsStripped,
        ];
    }

    public function delete(MediaAsset $asset, bool $force = false): void
    {
        app(MediaLibraryService::class)->delete($asset, $force);
    }

    /**
     * The storage quota is checked before the write so a workspace at its
     * limit gets a clear message instead of a half-finished upload.
     */
    private function assertTenantHasRoom(User $uploader, int $requestedBytes): void
    {
        $tenant = Tenant::query()->find($uploader->tenant_id);

        if ($tenant === null) {
            throw MediaUploadException::quotaExceeded(0, 0, (int) ceil($requestedBytes / 1048576));
        }

        $limitMb = (int) ($tenant->storage_limit_mb ?? 0);

        if ($limitMb <= 0) {
            return;
        }

        $usedBytes = (int) MediaAsset::query()->sum('file_size');

        if ($usedBytes + $requestedBytes > $limitMb * 1024 * 1024) {
            throw MediaUploadException::quotaExceeded(
                $limitMb,
                (int) round($usedBytes / 1048576),
                (int) ceil($requestedBytes / 1048576),
            );
        }
    }

    private function displayName(UploadedFile $file): string
    {
        $name = basename((string) $file->getClientOriginalName());

        return Str::limit($name !== '' ? $name : 'upload', 255, '');
    }

    private function altText(?string $altText): ?string
    {
        $trimmed = trim((string) $altText);

        return $trimmed === '' ? null : Str::limit($trimmed, 255, '');
    }

    private function folder(?string $folder): ?string
    {
        $trimmed = trim((string) $folder);

        return $trimmed === '' ? null : Str::limit($trimmed, 255, '');
    }

    /**
     * @param  array<mixed>  $tags
     * @return list<string>
     */
    private function normaliseTags(array $tags): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $value = Str::lower(trim($tag));

            if ($value !== '' && ! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        return $clean;
    }
}
