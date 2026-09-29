<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * A minimal, dependency-free EXIF/TIFF reader for JPEG APP1 segments.
 *
 * The `exif` extension is not guaranteed to be present, and a media library
 * that silently loses location data because an optional extension is missing is
 * not acceptable, so the tags we care about are parsed directly. Anything this
 * reader cannot make sense of is reported as "no EXIF", which is the safe
 * direction: the stripper then removes whatever APP1 payload exists regardless
 * of whether it could be decoded.
 */
final class ExifReader
{
    private const TIFF_TYPE_SIZES = [
        1 => 1,   // BYTE
        2 => 1,   // ASCII
        3 => 2,   // SHORT
        4 => 4,   // LONG
        5 => 8,   // RATIONAL
        6 => 1,   // SBYTE
        7 => 1,   // UNDEFINED
        8 => 2,   // SSHORT
        9 => 4,   // SLONG
        10 => 8,  // SRATIONAL
        11 => 4,  // FLOAT
        12 => 8,  // DOUBLE
    ];

    /**
     * Tags promoted to named keys in {@see toArray()}.
     *
     * @var array<int, string>
     */
    private const NAMED_TAGS = [
        0x010E => 'image_description',
        0x010F => 'make',
        0x0110 => 'model',
        0x0112 => 'orientation',
        0x011A => 'x_resolution',
        0x011B => 'y_resolution',
        0x0128 => 'resolution_unit',
        0x0131 => 'software',
        0x0132 => 'date_time',
        0x013B => 'artist',
        0x8298 => 'copyright',
        0x829A => 'exposure_time',
        0x829D => 'f_number',
        0x8827 => 'iso',
        0x9003 => 'date_time_original',
        0x9004 => 'date_time_digitized',
        0x920A => 'focal_length',
    ];

    /**
     * Every tag that lives in the GPS IFD (0x8825 points at it).
     *
     * @var list<int>
     */
    private const GPS_TAG_RANGE = [0x0000, 0x001F];

    /**
     * @var array<int, mixed>|null
     */
    private ?array $tags = null;

    private int $gpsIfdOffset = 0;

    private function __construct(private readonly string $tiff) {}

    /**
     * Reads the EXIF block of a JPEG's APP1 segment.
     *
     * The `Exif\0\0` prefix and the two-byte TIFF header are already removed:
     * `$tiff` starts at the byte-order marker.
     */
    public static function fromTiffBlock(string $tiff): self
    {
        return new self($tiff);
    }

    /**
     * @return array<int, mixed>
     */
    public function tags(): array
    {
        if ($this->tags !== null) {
            return $this->tags;
        }

        return $this->tags = $this->parse();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $named = [];

        foreach ($this->tags() as $tag => $value) {
            $name = self::NAMED_TAGS[$tag] ?? null;

            if ($name !== null) {
                $named[$name] = $value;
            }
        }

        $named['has_exif'] = $named !== [];
        $named['has_gps'] = $this->hasGps();

        return $named;
    }

    /**
     * Whether the image carries any GPS IFD entry.
     *
     * Checked structurally as well as by tag value, so a zeroed or malformed
     * coordinate still counts as location data and is removed.
     */
    public function hasGps(): bool
    {
        if ($this->tags === null) {
            $this->tags = $this->parse();
        }

        return $this->gpsIfdOffset !== 0;
    }

    /**
     * @return array<int, mixed>
     */
    private function parse(): array
    {
        if (strlen($this->tiff) < 8) {
            return [];
        }

        $byteOrder = substr($this->tiff, 0, 2);

        if ($byteOrder === 'II') {
            $little = true;
        } elseif ($byteOrder === 'MM') {
            $little = false;
        } else {
            return [];
        }

        $magic = $this->short(2, $little);

        if ($magic !== 42) {
            return [];
        }

        $ifd0 = $this->long(4, $little);

        return $this->readIfd($ifd0, $little);
    }

    /**
     * @return array<int, mixed>
     */
    private function readIfd(int $offset, bool $little): array
    {
        if ($offset <= 0 || $offset + 2 > strlen($this->tiff)) {
            return [];
        }

        $count = $this->short($offset, $little);
        $tags = [];
        $entry = $offset + 2;

        for ($i = 0; $i < $count; $i++, $entry += 12) {
            if ($entry + 12 > strlen($this->tiff)) {
                break;
            }

            $tag = $this->short($entry, $little);
            $type = $this->short($entry + 2, $little);
            $components = $this->long($entry + 4, $little);

            if ($type === 0 || ! isset(self::TIFF_TYPE_SIZES[$type])) {
                continue;
            }

            if ($tag === 0x8825) {
                // The GPS IFD pointer is a LONG whose value sits inline in the
                // four-byte value field, not in the component count.
                $this->gpsIfdOffset = $this->long($entry + 8, $little);

                continue;
            }

            $value = $this->value($entry, $type, $components, $little);

            if ($value !== null) {
                $tags[$tag] = $value;
            }
        }

        return $tags;
    }

    private function value(int $entry, int $type, int $components, bool $little): mixed
    {
        $size = self::TIFF_TYPE_SIZES[$type] * max(1, $components);
        $dataOffset = $size > 4 ? $this->long($entry + 8, $little) : $entry + 8;

        if ($dataOffset < 0 || $dataOffset + $size > strlen($this->tiff)) {
            return null;
        }

        $raw = substr($this->tiff, $dataOffset, $size);

        if ($type === 2) {
            return rtrim($raw, "\0");
        }

        if ($type === 7) {
            return 'yes';
        }

        $values = [];

        for ($i = 0; $i < $components; $i++) {
            $values[] = match ($type) {
                1, 6 => ord($raw[$i] ?? "\0"),
                3, 8 => $this->decode($raw, $i * 2, 2, $little, $type === 8),
                4, 9 => $this->decode($raw, $i * 4, 4, $little, $type === 9),
                5 => $this->rational($raw, $i * 8, $little),
                10 => $this->rational($raw, $i * 8, $little, true),
                11 => $this->float($raw, $i * 4, $little),
                12 => $this->double($raw, $i * 8, $little),
                default => null,
            };
        }

        return count($values) === 1 ? $values[0] : $values;
    }

    private function decode(string $raw, int $offset, int $size, bool $little, bool $signed): int
    {
        $slice = substr($raw, $offset, $size);

        $value = $size === 2
            ? ($little ? unpack('v', $slice) : unpack('n', $slice))
            : ($little ? unpack('V', $slice) : unpack('N', $slice));

        $number = (int) ($value[1] ?? 0);

        if (! $signed) {
            return $number;
        }

        $bits = $size * 8;
        $max = ($size === 2 ? 0x7FFF : 0x7FFFFFFF);

        return $number > $max ? $number - ($max * 2 + 2) : $number;
    }

    /**
     * @return array{float, float}|float|null
     */
    private function rational(string $raw, int $offset, bool $little, bool $signed = false): float|array|null
    {
        $numerator = $this->decode($raw, $offset, 4, $little, $signed);
        $denominator = $this->decode($raw, $offset + 4, 4, $little, $signed);

        if ($denominator === 0) {
            return null;
        }

        $value = $numerator / $denominator;

        return is_float($value) && floor($value) === $value && abs($value) < 1e15
            ? (int) $value
            : round($value, 6);
    }

    private function float(string $raw, int $offset, bool $little): float
    {
        $slice = substr($raw, $offset, 4);
        $value = $little ? unpack('g', $slice) : unpack('G', $slice);

        return (float) ($value[1] ?? 0.0);
    }

    private function double(string $raw, int $offset, bool $little): float
    {
        $slice = substr($raw, $offset, 8);
        $value = $little ? unpack('e', $slice) : unpack('E', $slice);

        return (float) ($value[1] ?? 0.0);
    }

    private function short(int $offset, bool $little): int
    {
        $slice = substr($this->tiff, $offset, 2);

        if (strlen($slice) < 2) {
            return 0;
        }

        $value = $little ? unpack('v', $slice) : unpack('n', $slice);

        return (int) ($value[1] ?? 0);
    }

    private function long(int $offset, bool $little): int
    {
        $slice = substr($this->tiff, $offset, 4);

        if (strlen($slice) < 4) {
            return 0;
        }

        $value = $little ? unpack('V', $slice) : unpack('N', $slice);

        return (int) ($value[1] ?? 0);
    }

    /**
     * @return list<int>
     */
    public static function gpsTags(): array
    {
        $tags = [];

        foreach (self::GPS_TAG_RANGE as $start) {
            for ($tag = $start; $tag < $start + 0x20 && $tag <= 0x001F; $tag++) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
