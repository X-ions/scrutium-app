<?php

declare(strict_types=1);

namespace Tests\Support\Engagement;

/**
 * Byte-level fixtures for the upload tests.
 *
 * The interesting cases — MIME spoofing, an HTML payload named `.png`, a JPEG
 * carrying GPS EXIF — are only meaningful if the bytes are real, so these build
 * actual container headers rather than writing text with a misleading extension.
 */
final class MediaFixture
{
    /**
     * A structurally valid, tiny baseline JPEG (SOI + APP0/JFIF + APP1/Exif + EOI).
     *
     * The entropy-coded data is a single placeholder byte rather than a real
     * scan: `getimagesize()` and `finfo` read the headers, which is all the
     * validator uses, and a byte-level EXIF test does not decode the image.
     *
     * @param  array<string, mixed>|null  $gps
     */
    public static function jpegWithExif(?array $gps = null): string
    {
        $exif = $gps === null ? '' : self::exifSegment($gps);

        return "\xFF\xD8"
            .self::jpegSegment(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
            .$exif
            .self::jpegSegment(0xFE, "\x00\x01\x00\x03\x00\x01\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00")
            ."\xFF\xD9";
    }

    /**
     * A JPEG with no EXIF segment at all, so the "nothing to strip" path is
     * exercised too.
     */
    public static function plainJpeg(): string
    {
        return "\xFF\xD8"
            .self::jpegSegment(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
            ."\xFF\xD9";
    }

    /**
     * Real PNG signature and IHDR, so `finfo` reports `image/png`.
     */
    public static function png(): string
    {
        $ihdr = pack('N', 2).pack('N', 2)."\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n"
            .self::pngChunk('IHDR', $ihdr)
            .self::pngChunk('IDAT', "\x78\x9C\x63\x00\x00\x00\x02\x00\x01")
            .self::pngChunk('IEND', '');
    }

    /**
     * A real PNG carrying an `eXIf` chunk, which is where PNG stores EXIF and
     * therefore where a PNG's GPS data would live.
     *
     * @param  array<string, mixed>  $gps
     */
    public static function pngWithExif(array $gps): string
    {
        return "\x89PNG\r\n\x1a\n"
            .self::pngChunk('IHDR', pack('N', 2).pack('N', 2)."\x08\x02\x00\x00\x00")
            .self::pngChunk('eXIf', self::tiffBlock($gps))
            .self::pngChunk('IDAT', "\x78\x9C\x63\x00\x00\x00\x02\x00\x01")
            .self::pngChunk('IEND', '');
    }

    /**
     * Bytes that are genuinely HTML, whatever the filename claims.
     */
    public static function html(): string
    {
        return "<!DOCTYPE html>\n<html><head><script>alert(document.cookie)</script></head><body>hi</body></html>";
    }

    /**
     * Bytes that are genuinely SVG, with script inside — the classic stored-XSS
     * payload, and the reason SVG is never on the allow-list.
     */
    public static function svgWithScript(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    }

    /**
     * A minimal PE/MZ header: a Windows executable.
     */
    public static function executable(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00"
            .str_repeat("\x00", 64)
            .'This program cannot be run in DOS mode.';
    }

    /**
     * A shell script with a shebang.
     */
    public static function shellScript(): string
    {
        return "#!/bin/sh\nrm -rf /\n";
    }

    /**
     * A real ISO base media file header: `ftyp` + an `mvhd` with a 30-second
     * timescale-1000 duration, so the container parser reports 30.0 seconds.
     */
    public static function mp4(float $durationSeconds = 30.0): string
    {
        $timescale = 1000;
        $duration = (int) round($durationSeconds * $timescale);

        $mvhd = pack('N', 0)           // version 0 + flags
            .pack('N', 0)              // creation time
            .pack('N', 0)              // modification time
            .pack('N', $timescale)
            .pack('N', $duration)
            .pack('N', 0x00010000)     // rate
            .pack('n', 0x0100)         // volume
            ."\x00\x00";               // reserved

        $mvhdBox = self::box('mvhd', $mvhd);
        $moovBox = self::box('moov', $mvhdBox);

        return self::box('ftyp', 'isom'.pack('N', 512).'isomiso2mp41').$moovBox;
    }

    /**
     * @param  array<string, mixed>  $gps
     */
    public static function exifSegment(array $gps): string
    {
        return self::jpegSegment(0xE1, "Exif\x00\x00".self::tiffBlock($gps));
    }

    /**
     * A little-endian TIFF block with IFD0 plus a GPS IFD, which is the smallest
     * structure that actually carries coordinates.
     *
     * Every offset below is computed from the layout rather than hard-coded,
     * because a wrong offset produces a block that parses as "no EXIF" — which
     * would make a GPS-stripping test pass for the wrong reason.
     *
     * @param  array<string, mixed>  $gps
     */
    public static function tiffBlock(array $gps): string
    {
        $header = 'II'.pack('v', 42).pack('V', 8);

        $ifd0Count = 2;
        $ifd0Size = 2 + ($ifd0Count * 12) + 4;
        $ifd0Offset = strlen($header);
        $makeOffset = $ifd0Offset + $ifd0Size;
        $make = "TestCam\0";

        $gpsIfdOffset = $makeOffset + strlen($make);
        $gpsCount = 4;
        $gpsIfdSize = 2 + ($gpsCount * 12) + 4;
        $gpsDataOffset = $gpsIfdOffset + $gpsIfdSize;

        // Latitude then longitude, each three rationals, stored past the IFDs.
        $lat = self::rationals([[(int) $gps['lat_deg'], 1], [(int) $gps['lat_min'], 1], [0, 1]]);
        $lon = self::rationals([[(int) $gps['lon_deg'], 1], [(int) $gps['lon_min'], 1], [0, 1]]);

        $latOffset = $gpsDataOffset;
        $lonOffset = $latOffset + strlen($lat);

        $ifd0 = pack('v', $ifd0Count)
            .pack('v', 0x010F).pack('v', 2).pack('V', strlen($make)).pack('V', $makeOffset)
            .pack('v', 0x8825).pack('v', 4).pack('V', 1).pack('V', $gpsIfdOffset)
            .pack('V', 0);

        // Two-character ASCII refs fit inline in the 4-byte value field; the
        // rationals do not, so they carry an offset.
        $gpsIfd = pack('v', $gpsCount)
            .pack('v', 0x0001).pack('v', 2).pack('V', 2).'N'."\x00\x00"
            .pack('v', 0x0002).pack('v', 5).pack('V', 3).pack('V', $latOffset)
            .pack('v', 0x0003).pack('v', 2).pack('V', 2).'E'."\x00\x00"
            .pack('v', 0x0004).pack('v', 5).pack('V', 3).pack('V', $lonOffset)
            .pack('V', 0);

        return $header.$ifd0.$make.$gpsIfd.$lat.$lon;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $pairs
     */
    private static function rationals(array $pairs): string
    {
        $out = '';

        foreach ($pairs as [$numerator, $denominator]) {
            $out .= pack('V', $numerator).pack('V', $denominator);
        }

        return $out;
    }

    private static function jpegSegment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', strlen($payload) + 8).$type.$payload;
    }

    /**
     * A file of a given size that is genuinely a JPEG, for the size-cap test.
     */
    public static function jpegOfSize(int $bytes): string
    {
        $header = self::plainJpeg();
        $padding = max(0, $bytes - strlen($header) - 2);

        return $header.str_repeat("\x00", $padding)."\xFF\xD9";
    }

    /**
     * Coordinates in a recognisable place, so a test can assert they are gone.
     *
     * @return array<string, mixed>
     */
    public static function londonGps(): array
    {
        return ['lat_deg' => 51, 'lat_min' => 30, 'lon_deg' => 0, 'lon_min' => 7];
    }
}
