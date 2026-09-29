<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Removes location and device metadata from uploaded images.
 *
 * The stored bytes are rewritten rather than the metadata merely being hidden,
 * because the privacy requirement is that a photo of a user's home does not
 * travel to a social network with the coordinates still embedded. The whole
 * EXIF/EXIF-equivalent block is dropped:
 *
 * - **JPEG** — the `APP1` segment carrying `Exif\0\0` is removed and the
 *   remaining segments are re-emitted, which also removes the GPS IFD.
 * - **PNG** — the ancillary `eXIf` chunk is dropped.
 * - **WebP** — the `EXIF` RIFF chunk is dropped.
 * - **GIF / MP4 / MOV / WebM** — the formats we accept carry no GPS block this
 *   code path can reach, so the bytes are returned unchanged.
 *
 * Non-EXIF entries are not selectively preserved: keeping "camera model" while
 * deleting "GPS" means re-encoding a TIFF IFD correctly for every container,
 * and a partial re-encode is how location data survives. Removing the block is
 * the only encoding we can prove is clean.
 */
final class ImageSanitizer
{
    private const EXIF_HEADER = "Exif\0\0";

    /**
     * @return array{bytes: string, removed_exif: bool}
     */
    public function sanitize(string $bytes, string $mimeType): array
    {
        return match ($mimeType) {
            'image/jpeg' => ['bytes' => $this->stripJpegApp1($bytes), 'removed_exif' => $this->hasJpegExif($bytes)],
            'image/png' => $this->stripChunk($bytes, 'eXIf', "\x89PNG\r\n\x1a\n"),
            'image/webp' => $this->stripRiff($bytes, 'EXIF'),
            default => ['bytes' => $bytes, 'removed_exif' => false],
        };
    }

    /**
     * The EXIF fields kept in `media_assets.metadata`; GPS is never among them.
     *
     * @return array<string, mixed>
     */
    public function extract(string $bytes, string $mimeType): array
    {
        if ($mimeType !== 'image/jpeg') {
            return [
                'exif' => [],
                'exif_removed' => false,
                'gps_stripped' => false,
            ];
        }

        $block = $this->jpegExifBlock($bytes);

        if ($block === null) {
            return [
                'exif' => [],
                'exif_removed' => false,
                'gps_stripped' => false,
            ];
        }

        $reader = ExifReader::fromTiffBlock($block);
        $hasGps = $reader->hasGps();

        $extracted = $reader->toArray();
        unset($extracted['has_gps']);
        $extracted['has_gps'] = false;

        return [
            'exif' => $extracted,
            'exif_removed' => true,
            'gps_stripped' => $hasGps,
        ];
    }

    public function hasJpegExif(string $bytes): bool
    {
        return $this->jpegExifBlock($bytes) !== null;
    }

    /**
     * The TIFF block of the first `Exif\0\0` APP1 segment, or null.
     */
    private function jpegExifBlock(string $bytes): ?string
    {
        if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $offset = 2;

        while ($offset + 4 <= strlen($bytes)) {
            if ($bytes[$offset] !== "\xFF") {
                $offset++;

                continue;
            }

            $marker = ord($bytes[$offset + 1] ?? "\0");
            $offset += 2;

            // Standalone markers carry no length payload.
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }

            $length = unpack('n', substr($bytes, $offset, 2));
            $segmentLength = (int) ($length[1] ?? 0);

            if ($segmentLength < 2) {
                break;
            }

            $payload = substr($bytes, $offset + 2, $segmentLength - 2);

            if ($marker === 0xE1 && str_starts_with($payload, self::EXIF_HEADER)) {
                return substr($payload, strlen(self::EXIF_HEADER));
            }

            $offset += $segmentLength;
        }

        return null;
    }

    private function stripJpegApp1(string $bytes): string
    {
        if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return $bytes;
        }

        $out = "\xFF\xD8";
        $offset = 2;

        while ($offset + 4 <= strlen($bytes)) {
            if ($bytes[$offset] !== "\xFF") {
                $out .= $bytes[$offset];
                $offset++;

                continue;
            }

            $marker = ord($bytes[$offset + 1] ?? "\0");
            $segmentStart = $offset;
            $offset += 2;

            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= substr($bytes, $segmentStart, 2);

                continue;
            }

            if ($marker === 0xDA) {
                // Start of scan: the entropy-coded remainder is copied verbatim.
                return $out.substr($bytes, $segmentStart);
            }

            $length = unpack('n', substr($bytes, $offset, 2));
            $segmentLength = (int) ($length[1] ?? 0);

            if ($segmentLength < 2) {
                return $out.substr($bytes, $segmentStart);
            }

            $segment = substr($bytes, $segmentStart, 2 + $segmentLength);
            $payload = substr($segment, 4, $segmentLength - 2);

            if (! ($marker === 0xE1 && str_starts_with($payload, self::EXIF_HEADER))) {
                $out .= $segment;
            }

            $offset += $segmentLength;
        }

        // The loop ended because the buffer ran out, not because a terminator
        // was found: whatever is left (normally EOI) still belongs in the file.
        return $out.substr($bytes, $offset);
    }

    /**
     * @return array{bytes: string, removed_exif: bool}
     */
    private function stripChunk(string $bytes, string $chunkType, string $signature): array
    {
        if (! str_starts_with($bytes, $signature)) {
            return ['bytes' => $bytes, 'removed_exif' => false];
        }

        $out = $signature;
        $offset = strlen($signature);
        $removed = false;

        while ($offset + 8 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $offset, 4));
            $length = (int) ($length[1] ?? 0);
            $type = substr($bytes, $offset + 4, 4);
            $total = 12 + $length;

            if ($offset + $total > strlen($bytes)) {
                $out .= substr($bytes, $offset);

                break;
            }

            if ($type !== $chunkType) {
                $out .= substr($bytes, $offset, $total);
            } else {
                $removed = true;
            }

            $offset += $total;
        }

        return ['bytes' => $out, 'removed_exif' => $removed];
    }

    /**
     * @return array{bytes: string, removed_exif: bool}
     */
    private function stripRiff(string $bytes, string $chunkId): array
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF') {
            return ['bytes' => $bytes, 'removed_exif' => false];
        }

        $size = unpack('V', substr($bytes, 4, 4));
        $size = (int) ($size[1] ?? 0);
        $body = substr($bytes, 8, min($size, strlen($bytes) - 8));

        $out = '';
        $offset = 0;
        $removed = false;

        while ($offset + 8 <= strlen($body)) {
            $chunkSize = unpack('V', substr($body, $offset + 4, 4));
            $chunkSize = (int) ($chunkSize[1] ?? 0);
            $padded = $chunkSize + ($chunkSize % 2);
            $id = substr($body, $offset, 4);
            $chunk = substr($body, $offset, 8 + $padded);

            if ($id !== $chunkId) {
                $out .= $chunk;
            } else {
                $removed = true;
            }

            $offset += 8 + $padded;
        }

        if (! $removed) {
            return ['bytes' => $bytes, 'removed_exif' => false];
        }

        return ['bytes' => 'RIFF'.pack('V', strlen($out) + 4).'WEBP'.$out, 'removed_exif' => true];
    }
}
