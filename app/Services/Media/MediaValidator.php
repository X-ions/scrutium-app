<?php

declare(strict_types=1);

namespace App\Services\Media;

use finfo;

/**
 * Server-side type gate for uploads.
 *
 * The client tells us what it thinks it is sending; this class decides. The
 * rules, in the order they run:
 *
 * 1. **Non-empty.** A zero-byte upload is a failed request, not an asset.
 * 2. **Sniffed MIME on the allow-list.** `finfo` reads magic bytes. A file
 *    whose real type is `text/html`, `image/svg+xml` or `application/x-msdownload`
 *    is rejected even if the browser said `image/png` — an SVG can carry script
 *    and an HTML file served from our own origin is stored XSS.
 * 3. **Extension on the allow-list.** Both gates must pass; one alone is not
 *    evidence.
 * 4. **No double extension.** `invoice.pdf.exe` has one allowed segment and one
 *    that is not, which is the shape of every download-attachment trick.
 * 5. **Declared MIME must not contradict the sniffed one.** `photo.jpg` whose
 *    bytes are a PNG is a rename, not an upload, and the sniff wins.
 * 6. **Size cap** from `config('socialhub.media.max_size_kb')`.
 */
class MediaValidator
{
    /**
     * Types that are actively dangerous to store and serve, listed separately
     * from the allow-list so a future config edit cannot quietly add one.
     *
     * @var list<string>
     */
    private const FORBIDDEN_MIME = [
        'image/svg+xml',
        'text/html',
        'text/xml',
        'application/xhtml+xml',
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-sh',
        'application/x-executable',
        'application/x-dosexec',
        'application/javascript',
        'text/javascript',
        'application/x-httpd-php',
        'application/x-perl',
        'application/x-bat',
        'application/x-msi',
        'application/x-apple-diskimage',
    ];

    /**
     * Extension -> the MIME types that extension may legitimately carry.
     * `jpeg` also accepts `image/jpg`, which some encoders emit.
     *
     * @var array<string, list<string>>
     */
    private const EXTENSION_MIME_MAP = [
        'jpg' => ['image/jpeg', 'image/jpg'],
        'jpeg' => ['image/jpeg', 'image/jpg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'mp4' => ['video/mp4'],
        'mov' => ['video/quicktime'],
        'webm' => ['video/webm', 'audio/webm'],
    ];

    private finfo $finfo;

    public function __construct(private readonly ImageSanitizer $sanitizer = new ImageSanitizer)
    {
        $this->finfo = new finfo(FILEINFO_MIME_TYPE);
    }

    /**
     * @throws MediaUploadException
     */
    public function validate(string $path, string $clientFilename, ?string $clientMimeType = null, ?int $sizeBytes = null): MediaInspector
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw MediaUploadException::unreadable('The temporary upload file is missing or unreadable.');
        }

        $size = $sizeBytes ?? (int) filesize($path);

        if ($size <= 0) {
            throw MediaUploadException::empty();
        }

        $maxKb = (int) config('socialhub.media.max_size_kb', 2048);

        if ($maxKb > 0 && $size > $maxKb * 1024) {
            throw MediaUploadException::tooLarge($maxKb, (int) ceil($size / 1024));
        }

        $filename = $this->safeClientFilename($clientFilename);
        $segments = $this->extensionSegments($filename);
        $extension = $segments['extension'] ?? '';

        $this->assertNoDoubleExtension($filename, $segments);

        $sniffed = $this->sniff($path);
        $allowedMime = $this->allowedMime();
        $allowedExtensions = $this->allowedExtensions();

        if ($sniffed === null || $sniffed === '' || $sniffed === 'application/octet-stream') {
            throw MediaUploadException::unreadable(sprintf('finfo could not identify the uploaded bytes (reported "%s").', (string) $sniffed));
        }

        if (in_array(strtolower($sniffed), self::FORBIDDEN_MIME, true)) {
            throw MediaUploadException::typeNotAllowed($sniffed, $extension);
        }

        if (! in_array(strtolower($sniffed), $allowedMime, true)) {
            throw MediaUploadException::typeNotAllowed($sniffed, $extension);
        }

        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            throw MediaUploadException::typeNotAllowed($sniffed, $extension);
        }

        $permittedForExtension = self::EXTENSION_MIME_MAP[$extension] ?? [$sniffed];

        if (! in_array(strtolower($sniffed), $permittedForExtension, true)) {
            throw MediaUploadException::mimeMismatch((string) $clientMimeType, $sniffed, $extension);
        }

        $this->assertNoEmbeddedMarkup($path, $sniffed);

        $bytes = (string) file_get_contents($path);

        $exif = [];
        $gpsStripped = false;
        $exifRemoved = false;

        if (str_starts_with($sniffed, 'image/')) {
            $extracted = $this->sanitizer->extract($bytes, $sniffed);
            $exif = $extracted['exif'];
            $gpsStripped = (bool) $extracted['gps_stripped'];
            $exifRemoved = (bool) $extracted['exif_removed'];
        }

        $dimensions = $this->dimensions($path, $sniffed);

        return new MediaInspector(
            mimeType: strtolower($sniffed),
            extension: $extension,
            byteSize: $size,
            width: $dimensions['width'],
            height: $dimensions['height'],
            durationSeconds: $this->duration($path, $sniffed),
            checksum: hash('sha256', $bytes),
            exif: $exif,
            gpsStripped: $gpsStripped,
            exifRemoved: $exifRemoved,
        );
    }

    /**
     * The bytes to persist: metadata stripped for images, verbatim otherwise.
     */
    public function sanitizedBytes(string $path, MediaInspector $inspector): string
    {
        $bytes = (string) file_get_contents($path);

        if (! $inspector->isImage()) {
            return $bytes;
        }

        return $this->sanitizer->sanitize($bytes, $inspector->mimeType)['bytes'];
    }

    /**
     * @return array<string, mixed>
     */
    public function allowedMime(): array
    {
        return array_map(
            static fn (mixed $mime): string => strtolower(trim((string) $mime)),
            (array) config('socialhub.media.allowed_mime', []),
        );
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return array_values(array_map(
            static fn (mixed $extension): string => strtolower(trim((string) $extension, ". \t")),
            (array) config('socialhub.media.allowed_extensions', []),
        ));
    }

    /**
     * The MIME type chosen for the stored object, derived from the sniffed one.
     */
    public function storageExtensionFor(MediaInspector $inspector): string
    {
        return $inspector->extension;
    }

    private function sniff(string $path): ?string
    {
        $mime = $this->finfo->file($path);

        return is_string($mime) ? $mime : null;
    }

    /**
     * A conservative reading of the client filename: no directory component, no
     * control characters, no null bytes. It is used only to pick the candidate
     * extension — the real name on disk is a UUID.
     */
    private function safeClientFilename(string $filename): string
    {
        $filename = str_replace(["\0", "\r", "\n", "\t"], '', $filename);
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';

        return trim($filename) === '' ? 'upload' : trim($filename);
    }

    /**
     * @return array{extension?: string, segments: list<string>}
     */
    private function extensionSegments(string $filename): array
    {
        $segments = array_values(array_filter(
            explode('.', $filename),
            static fn (string $segment): bool => $segment !== '',
        ));

        $last = $segments === [] ? null : strtolower((string) end($segments));

        return [
            'extension' => $last,
            'segments' => array_map('strtolower', $segments),
        ];
    }

    /**
     * @param  array{extension?: string, segments: list<string>}  $segments
     */
    private function assertNoDoubleExtension(string $filename, array $segments): void
    {
        $parts = $segments['segments'];

        if (count($parts) < 2) {
            return;
        }

        // `archive.tar.gz` is a legitimate double extension; a leading segment
        // that is a known executable or script extension is not.
        $dangerous = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'com', 'scr', 'js', 'mjs', 'cjs', 'sh', 'bash', 'ps1', 'jar', 'msi', 'htm', 'html', 'shtml', 'svg', 'cgi', 'pl'];

        foreach (array_slice($parts, 0, -1) as $segment) {
            if (in_array($segment, $dangerous, true)) {
                throw MediaUploadException::doubleExtension($filename);
            }
        }
    }

    /**
     * A file that claims to be an image but starts with markup is a polyglot.
     * The allow-list already excludes the markup types; this catches a JPEG
     * whose comment segment carries a script payload.
     */
    private function assertNoEmbeddedMarkup(string $path, string $sniffed): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);

        if (str_starts_with($sniffed, 'image/') && preg_match('/<\s*(script|svg|html|iframe)\b/i', $head) === 1) {
            throw MediaUploadException::typeNotAllowed($sniffed, '');
        }
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private function dimensions(string $path, string $mimeType): array
    {
        if ($mimeType === 'image/gif' || $mimeType === 'image/png' || $mimeType === 'image/jpeg' || $mimeType === 'image/webp') {
            $size = @getimagesize($path);

            if (is_array($size)) {
                return [
                    'width' => isset($size[0]) ? (int) $size[0] : null,
                    'height' => isset($size[1]) ? (int) $size[1] : null,
                ];
            }
        }

        return ['width' => null, 'height' => null];
    }

    /**
     * Container duration for the video formats we accept.
     *
     * Read from the file's own header rather than by shelling out to ffprobe:
     * the ISO base media box (`mvhd`) and the Matroska `Duration` element cover
     * MP4, MOV and WebM, and a missing duration is reported as null rather than
     * guessed at.
     */
    private function duration(string $path, string $mimeType): ?float
    {
        return match ($mimeType) {
            'video/mp4', 'video/quicktime' => $this->mp4Duration($path),
            'video/webm' => $this->matroskaDuration($path),
            default => null,
        };
    }

    private function mp4Duration(string $path): ?float
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $size = (int) (filesize($path) ?: 0);
            $offset = 0;
            $buffer = '';

            while ($offset < $size && strlen($buffer) < 8_388_608) {
                $header = fread($handle, 8);

                if ($header === false || strlen($header) < 8) {
                    break;
                }

                $boxSize = unpack('N', substr($header, 0, 4));
                $type = substr($header, 4, 4);
                $boxSize = (int) ($boxSize[1] ?? 0);

                if ($boxSize === 1) {
                    $extended = fread($handle, 8);

                    if ($extended === false || strlen($extended) < 8) {
                        break;
                    }

                    $parts = unpack('Nhi/Nlow', $extended);
                    $boxSize = (int) (($parts['hi'] ?? 0) * 4294967296 + ($parts['low'] ?? 0));
                }

                $payloadLength = $boxSize - 8;

                if ($boxSize < 8 || $payloadLength < 0) {
                    break;
                }

                if ($type === 'moov' || $type === 'trak' || $type === 'mdia') {
                    $payload = (string) fread($handle, min($payloadLength, 4_194_304));
                    $buffer .= $payload;
                    $duration = $this->mvhdDuration($payload);

                    if ($duration !== null) {
                        return $duration;
                    }
                } else {
                    fseek($handle, $payloadLength, SEEK_CUR);
                }

                $offset += $boxSize;
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private function mvhdDuration(string $payload): ?float
    {
        $position = strpos($payload, 'mvhd');

        if ($position === false) {
            return null;
        }

        $cursor = $position + 4;

        if (strlen($payload) < $cursor + 20) {
            return null;
        }

        $version = ord($payload[$cursor]);
        $cursor += 4;

        if ($version === 1) {
            $cursor += 16;

            if (strlen($payload) < $cursor + 8) {
                return null;
            }

            $timescale = unpack('N', substr($payload, $cursor, 4));
            $high = unpack('N', substr($payload, $cursor + 4, 4));
            $low = unpack('N', substr($payload, $cursor + 8, 4));

            $duration = ((int) ($high[1] ?? 0) * 4294967296) + (int) ($low[1] ?? 0);
        } else {
            $cursor += 8;

            if (strlen($payload) < $cursor + 8) {
                return null;
            }

            $timescale = unpack('N', substr($payload, $cursor, 4));
            $durationValue = unpack('N', substr($payload, $cursor + 4, 4));
            $duration = (int) ($durationValue[1] ?? 0);
        }

        $scale = (int) ($timescale[1] ?? 0);

        if ($scale <= 0 || $duration <= 0) {
            return null;
        }

        return round($duration / $scale, 3);
    }

    private function matroskaDuration(string $path): ?float
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $head = (string) fread($handle, 65_536);

            // 0x4489 is the Segment > Info > Duration element: a float size and a
            // float payload, in the segment's own timecode scale.
            $position = strpos($head, "\x44\x89");

            while ($position !== false) {
                $length = ord($head[$position + 1] ?? "\0");
                $payload = substr($head, $position + 2, $length);

                if ($length === 4 || $length === 8) {
                    $value = $length === 4
                        ? (float) unpack('G', $payload)[1]
                        : (float) unpack('E', $payload)[1];

                    if ($value > 0) {
                        return round($value / 1000, 3);
                    }
                }

                $position = strpos($head, "\x44\x89", $position + 1);
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
