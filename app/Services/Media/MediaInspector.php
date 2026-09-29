<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * The server's reading of a file, derived only from the bytes.
 *
 * Every field here comes from the file itself — the client's `Content-Type`,
 * the browser-supplied filename and the declared MIME are all ignored. The
 * validator compares the sniffed result against the allow-list, so a caller
 * cannot talk its way past the type gate.
 */
final readonly class MediaInspector
{
    /**
     * @param  array<string, mixed>  $exif
     */
    public function __construct(
        public string $mimeType,
        public string $extension,
        public int $byteSize,
        public ?int $width = null,
        public ?int $height = null,
        public ?float $durationSeconds = null,
        public string $checksum = '',
        public array $exif = [],
        public bool $gpsStripped = false,
        public bool $exifRemoved = false,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mimeType, 'video/');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mime_type' => $this->mimeType,
            'extension' => $this->extension,
            'byte_size' => $this->byteSize,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->durationSeconds,
            'checksum' => $this->checksum,
            'exif' => $this->exif,
            'exif_removed' => $this->exifRemoved,
            'gps_stripped' => $this->gpsStripped,
        ];
    }
}
