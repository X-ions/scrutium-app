<?php

declare(strict_types=1);

use App\Services\Media\ExifReader;
use App\Services\Media\ImageSanitizer;
use Tests\Support\Engagement\MediaFixture;

describe('ExifReader', function (): void {
    it('reads the camera make out of a JPEG APP1 segment', function (): void {
        $jpeg = MediaFixture::jpegWithExif(MediaFixture::londonGps());
        $block = substr($jpeg, (int) strpos($jpeg, "Exif\0\0") + 6);

        $reader = ExifReader::fromTiffBlock($block);

        expect($reader->toArray()['make'])->toBe('TestCam')
            ->and($reader->tags()[0x010F])->toBe('TestCam');
    });

    it('detects a GPS IFD even when no coordinates were decoded', function (): void {
        $jpeg = MediaFixture::jpegWithExif(MediaFixture::londonGps());
        $block = substr($jpeg, (int) strpos($jpeg, "Exif\0\0") + 6);

        expect(ExifReader::fromTiffBlock($block)->hasGps())->toBeTrue();
    });

    it('reports no EXIF for a block that is not a TIFF header', function (): void {
        $reader = ExifReader::fromTiffBlock('not an exif block at all');

        expect($reader->tags())->toBe([])
            ->and($reader->hasGps())->toBeFalse()
            ->and($reader->toArray())->toBe(['has_exif' => false, 'has_gps' => false]);
    });

    it('rejects a big-endian block with a wrong magic number rather than guessing', function (): void {
        $block = 'MM'.pack('n', 41).pack('N', 8);

        expect(ExifReader::fromTiffBlock($block)->tags())->toBe([]);
    });

    it('survives a truncated block without throwing', function (): void {
        $jpeg = MediaFixture::jpegWithExif(MediaFixture::londonGps());
        $block = substr($jpeg, (int) strpos($jpeg, "Exif\0\0") + 6, 12);

        expect(ExifReader::fromTiffBlock($block)->tags())->toBe([]);
    });
});

describe('ImageSanitizer', function (): void {
    it('reports what it removed and what it kept', function (): void {
        $sanitizer = new ImageSanitizer;
        $jpeg = MediaFixture::jpegWithExif(MediaFixture::londonGps());

        $extract = $sanitizer->extract($jpeg, 'image/jpeg');
        $sanitized = $sanitizer->sanitize($jpeg, 'image/jpeg');

        expect($extract['gps_stripped'])->toBeTrue()
            ->and($extract['exif_removed'])->toBeTrue()
            ->and($extract['exif']['has_gps'])->toBeFalse()
            ->and($sanitized['removed_exif'])->toBeTrue();
    });

    it('never returns a GPS IFD to the caller as readable metadata', function (): void {
        $sanitizer = new ImageSanitizer;
        $extract = $sanitizer->extract(MediaFixture::jpegWithExif(MediaFixture::londonGps()), 'image/jpeg');

        expect($extract['exif'])->not->toHaveKey('latitude')
            ->not->toHaveKey('longitude')
            ->and($extract['exif']['has_gps'])->toBeFalse();
    });

    it('leaves a non-image container untouched', function (): void {
        $sanitizer = new ImageSanitizer;
        $mp4 = MediaFixture::mp4(12.0);

        expect($sanitizer->sanitize($mp4, 'video/mp4'))->toBe(['bytes' => $mp4, 'removed_exif' => false])
            ->and($sanitizer->extract($mp4, 'video/mp4'))->toBe([
                'exif' => [],
                'exif_removed' => false,
                'gps_stripped' => false,
            ]);
    });

    it('returns a PNG without its eXIf chunk but otherwise intact', function (): void {
        $sanitizer = new ImageSanitizer;
        $png = MediaFixture::pngWithExif(MediaFixture::londonGps());

        $result = $sanitizer->sanitize($png, 'image/png');

        expect($result['removed_exif'])->toBeTrue()
            ->and($result['bytes'])->toStartWith("\x89PNG\r\n\x1a\n")
            ->and($result['bytes'])->toContain('IHDR')
            ->and($result['bytes'])->toContain('IDAT')
            ->and($result['bytes'])->toEndWith('IEND'.pack('N', crc32('IEND')))
            ->and($result['bytes'])->not->toContain('eXIf');
    });

    it('keeps every non-EXIF JPEG segment, including the one after the EXIF', function (): void {
        $sanitizer = new ImageSanitizer;
        $jpeg = MediaFixture::jpegWithExif(MediaFixture::londonGps());

        $sanitized = $sanitizer->sanitize($jpeg, 'image/jpeg')['bytes'];

        expect($sanitized)->toStartWith("\xFF\xD8")
            ->and($sanitized)->toContain('JFIF')
            ->and($sanitized)->toContain("\xFF\xFE")
            ->and($sanitized)->toEndWith("\xFF\xD9");
    });

    it('is idempotent: sanitizing twice changes nothing the second time', function (): void {
        $sanitizer = new ImageSanitizer;

        $once = $sanitizer->sanitize(MediaFixture::jpegWithExif(MediaFixture::londonGps()), 'image/jpeg');
        $twice = $sanitizer->sanitize($once['bytes'], 'image/jpeg');

        expect($twice['bytes'])->toBe($once['bytes'])
            ->and($twice['removed_exif'])->toBeFalse();
    });
});
