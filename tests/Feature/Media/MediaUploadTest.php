<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaUploadException;
use App\Services\Media\MediaUploadService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Engagement\MediaFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::forget();
    Storage::fake(config('socialhub.media.disk', 'local'));
});

afterEach(fn () => TenantContext::forget());

function mediaTenant(array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->create($attributes);

    TenantContext::set($tenant);

    return $tenant;
}

function mediaUser(?Tenant $tenant = null): User
{
    $tenant ??= mediaTenant();

    return User::factory()->create(['tenant_id' => $tenant->getKey()]);
}

/**
 * An `UploadedFile` over explicit bytes, so the fixture is not re-encoded by
 * the framework and the "real MIME differs from the extension" cases are real.
 */
function uploadWith(string $bytes, string $filename, ?string $declaredMime = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'sh-media-').'.bin';
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $filename, $declaredMime, null, true);
}

function uploader(): MediaUploadService
{
    return app(MediaUploadService::class);
}

// 1. MIME spoofing

it('rejects a file whose real type differs from its extension', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    // PNG bytes wearing a .jpg name: the browser said image/jpeg, the bytes say PNG.
    $spoofed = uploadWith(MediaFixture::png(), 'holiday.jpg', 'image/jpeg');

    expect(fn () => uploader()->upload($spoofed, $user))
        ->toThrow(MediaUploadException::class);

    try {
        uploader()->upload($spoofed, $user);
    } catch (MediaUploadException $e) {
        expect($e->reason)->toBe(MediaUploadException::REASON_MIME_MISMATCH)
            ->and($e->getMessage())->toContain('image/png')
            ->and($e->getMessage())->toContain('jpg');
    }

    expect(Storage::disk(config('socialhub.media.disk'))->allFiles())->toBe([]);
});

it('accepts a file whose extension genuinely matches its bytes', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $asset = uploader()->upload(uploadWith(MediaFixture::png(), 'holiday.png', 'image/png'), $user);

    expect($asset->mime_type)->toBe('image/png')
        ->and($asset->filename)->toBe('holiday.png')
        ->and($asset->width)->toBe(2)
        ->and($asset->height)->toBe(2)
        ->and(Storage::disk($asset->storage_disk)->exists($asset->storage_path))->toBeTrue();
});

it('does not trust the client declared mime type when the bytes disagree', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    // Correct extension, lying Content-Type: the extension gate and the sniff
    // still agree, so this is accepted — the point is that the declared type
    // played no part in the decision.
    $asset = uploader()->upload(uploadWith(MediaFixture::png(), 'chart.png', 'text/html'), $user);

    expect($asset->mime_type)->toBe('image/png');
});

it('rejects a double extension even when the final segment is allowed', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $file = uploadWith(MediaFixture::png(), 'invoice.pdf.php.png', 'image/png');

    expect(fn () => uploader()->upload($file, $user))
        ->toThrow(MediaUploadException::class, 'more than one extension');
});

it('rejects a path traversal attempt in the client filename', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $asset = uploader()->upload(
        uploadWith(MediaFixture::png(), '../../config/app.png', 'image/png'),
        $user,
    );

    expect($asset->filename)->toBe('app.png')
        ->and($asset->storage_path)->not->toContain('..')
        ->and($asset->stored_filename)->not->toBe('app.png');
});

// 2. Oversized, executable, HTML and SVG payloads

it('rejects a file over the configured size cap', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    config()->set('socialhub.media.max_size_kb', 1024);

    $file = uploadWith(MediaFixture::jpegOfSize(2 * 1024 * 1024), 'huge.jpg', 'image/jpeg');

    try {
        uploader()->upload($file, $user);
        $this->fail('The oversized upload should have been refused.');
    } catch (MediaUploadException $e) {
        expect($e->reason)->toBe(MediaUploadException::REASON_TOO_LARGE)
            ->and($e->userMessage())->toContain('1 MB');
    }
});

it('rejects an HTML payload named as an image', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $file = uploadWith(MediaFixture::html(), 'payload.png', 'image/png');

    expect(fn () => uploader()->upload($file, $user))
        ->toThrow(MediaUploadException::class);

    try {
        uploader()->upload($file, $user);
    } catch (MediaUploadException $e) {
        expect($e->reason)->toBe(MediaUploadException::REASON_TYPE_NOT_ALLOWED)
            ->and($e->getMessage())->toContain('text/html');
    }
});

it('rejects an SVG payload named as an image', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $file = uploadWith(MediaFixture::svgWithScript(), 'logo.png', 'image/png');

    expect(fn () => uploader()->upload($file, $user))
        ->toThrow(MediaUploadException::class, 'not an accepted media type');
});

it('rejects a windows executable', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $file = uploadWith(MediaFixture::executable(), 'setup.jpg', 'image/jpeg');

    expect(fn () => uploader()->upload($file, $user))
        ->toThrow(MediaUploadException::class);
});

it('rejects a shell script', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $file = uploadWith(MediaFixture::shellScript(), 'install.png', 'image/png');

    expect(fn () => uploader()->upload($file, $user))
        ->toThrow(MediaUploadException::class);
});

it('rejects an empty upload', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $file = uploadWith('', 'empty.png', 'image/png');

    expect(fn () => uploader()->upload($file, $user))
        ->toThrow(MediaUploadException::class, 'empty');
});

it('rejects bytes it cannot identify at all', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $file = uploadWith(str_repeat("\x00\x01\x02\x03", 64), 'mystery.png', 'image/png');

    expect(fn () => uploader()->upload($file, $user))
        ->toThrow(MediaUploadException::class);
});

// 3. GPS EXIF stripped

it('strips GPS EXIF from a stored jpeg and never records the coordinates', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $file = uploadWith(MediaFixture::jpegWithExif(MediaFixture::londonGps()), 'geotagged.jpg', 'image/jpeg');

    $asset = uploader()->upload($file, $user);

    $stored = Storage::disk($asset->storage_disk)->get($asset->storage_path);

    expect($asset->metadata['gps_stripped'])->toBeTrue()
        ->and($asset->metadata['has_gps'])->toBeFalse()
        ->and($asset->metadata['exif_removed'])->toBeTrue()
        ->and($asset->metadata['exif']['has_gps'])->toBeFalse()
        ->and($asset->metadata['exif']['make'])->toBe('TestCam');

    // The bytes on disk carry no EXIF block at all, so the coordinates cannot
    // be recovered by anything that later reads the object.
    expect($stored)->not->toContain('Exif')
        ->and($stored)->not->toContain('GPS')
        ->and(strlen($stored))->toBeLessThan(strlen(MediaFixture::jpegWithExif(MediaFixture::londonGps())));

    // The GPS tags (0x8825 and the latitude/longitude entries) must not survive
    // into anything persisted, in any form.
    $metadata = json_encode($asset->metadata);

    expect($metadata)->not->toContain('GPS')
        ->not->toContain('gps_lat')
        ->not->toContain('latitude')
        ->not->toContain('longitude')
        ->and($asset->metadata['exif'])->toBe(['make' => 'TestCam', 'has_exif' => true, 'has_gps' => false]);
});

it('strips the eXIf chunk from a stored png', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $file = uploadWith(MediaFixture::pngWithExif(MediaFixture::londonGps()), 'geotagged.png', 'image/png');

    $asset = uploader()->upload($file, $user);
    $stored = Storage::disk($asset->storage_disk)->get($asset->storage_path);

    expect($stored)->not->toContain('eXIf')
        ->and($asset->metadata['has_gps'])->toBeFalse();
});

it('keeps a jpeg with no exif byte-identical', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $original = MediaFixture::plainJpeg();
    $asset = uploader()->upload(uploadWith($original, 'plain.jpg', 'image/jpeg'), $user);

    $stored = Storage::disk($asset->storage_disk)->get($asset->storage_path);

    expect($stored)->toBe($original)
        ->and($asset->metadata['gps_stripped'])->toBeFalse()
        ->and($asset->metadata['exif_removed'])->toBeFalse();
});

// 4. Storage entitlement

it('enforces the tenant storage limit', function (): void {
    $tenant = mediaTenant(['storage_limit_mb' => 1]);
    $user = mediaUser($tenant);

    config()->set('socialhub.media.max_size_kb', 4096);

    // A little over 1 MiB, so it cannot fit inside the 1 MB allowance.
    $file = uploadWith(MediaFixture::jpegOfSize(2 * 1024 * 1024), 'big.jpg', 'image/jpeg');

    try {
        uploader()->upload($file, $user);
        $this->fail('The upload should have exceeded the tenant storage limit.');
    } catch (MediaUploadException $e) {
        expect($e->reason)->toBe(MediaUploadException::REASON_QUOTA_EXCEEDED)
            ->and($e->userMessage())->toContain('1 MB media allowance');
    }

    expect(\App\Models\MediaAsset::query()->count())->toBe(0);
});

it('counts existing usage against the tenant storage limit', function (): void {
    $tenant = mediaTenant(['storage_limit_mb' => 1]);
    $user = mediaUser($tenant);

    config()->set('socialhub.media.max_size_kb', 4096);

    \App\Models\MediaAsset::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'file_size' => 1024 * 1024,
    ]);

    $file = uploadWith(MediaFixture::jpegOfSize(256 * 1024), 'more.jpg', 'image/jpeg');

    expect(fn () => uploader()->upload($file, $user))
        ->toThrow(MediaUploadException::class);
});

it('lets a small upload through under a generous limit', function (): void {
    $tenant = mediaTenant(['storage_limit_mb' => 1024]);
    $user = mediaUser($tenant);

    $asset = uploader()->upload(uploadWith(MediaFixture::png(), 'ok.png', 'image/png'), $user);

    expect($asset->exists)->toBeTrue();
});

it('treats a null storage limit as unlimited', function (): void {
    $tenant = mediaTenant(['storage_limit_mb' => null]);
    $user = mediaUser($tenant);

    config()->set('socialhub.media.max_size_kb', 4096);

    $asset = uploader()->upload(
        uploadWith(MediaFixture::jpegOfSize(2 * 1024 * 1024), 'big.jpg', 'image/jpeg'),
        $user,
    );

    expect($asset->file_size)->toBeGreaterThan(1024 * 1024);
});

// Storage rules

it('never writes a user upload to the web-servable public disk in production', function (): void {
    $original = app()['env'];
    app()['env'] = 'production';
    config()->set('socialhub.media.disk', 'public');

    try {
        $storage = new MediaStorage(app('filesystem'));

        expect(fn () => $storage->disk('public'))
            ->toThrow(RuntimeException::class, 'web-servable');
    } finally {
        app()['env'] = $original;
        config()->set('socialhub.media.disk', 'local');
    }

    // The same disk is fine outside production, so local development is not broken.
    $storage = new MediaStorage(app('filesystem'));

    expect($storage->disk('public'))->not->toBeNull();
});

it('generates a uuid object name that is never the client filename', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $asset = uploader()->upload(uploadWith(MediaFixture::png(), 'my-report.png', 'image/png'), $user);

    expect($asset->stored_filename)->not->toBe('my-report.png')
        ->and($asset->stored_filename)->toEndWith('.png')
        ->and($asset->storage_path)->toContain('socialhub/media/'.$tenant->getKey().'/')
        ->and($asset->storage_path)->toContain($asset->stored_filename);
});

it('stores tags, alt text and folder as given', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $asset = uploader()->upload(
        uploadWith(MediaFixture::png(), 'tagged.png', 'image/png'),
        $user,
        folder: 'campaigns/launch',
        tags: ['Launch', 'launch', '  ', 'Q4'],
        altText: 'The product on a white background',
    );

    expect($asset->folder)->toBe('campaigns/launch')
        ->and($asset->tags)->toBe(['launch', 'q4'])
        ->and($asset->alt_text)->toBe('The product on a white background');
});

it('extracts real metadata including a checksum for a video', function (): void {
    $tenant = mediaTenant();
    $user = mediaUser($tenant);

    $bytes = MediaFixture::mp4(30.0);
    $asset = uploader()->upload(uploadWith($bytes, 'clip.mp4', 'video/mp4'), $user);

    expect($asset->mime_type)->toBe('video/mp4')
        ->and((float) $asset->duration)->toBe(30.0)
        ->and($asset->metadata['checksum_sha256'])->toBe(hash('sha256', $bytes))
        ->and($asset->file_size)->toBe(strlen($bytes));
});
