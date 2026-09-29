<?php

declare(strict_types=1);

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Media\MediaLibraryService;
use App\Services\Media\MediaUploadException;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Engagement\MediaFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::forget();
    Storage::fake('local');
});

afterEach(fn () => TenantContext::forget());

function library(): MediaLibraryService
{
    return app(MediaLibraryService::class);
}

/**
 * @return array{0: Tenant, 1: User}
 */
function libraryContext(): array
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);

    return [$tenant, User::factory()->create(['tenant_id' => $tenant->getKey()])];
}

function assetIn(?Tenant $tenant, array $attributes = []): MediaAsset
{
    return MediaAsset::factory()->create(array_merge([
        'tenant_id' => $tenant?->getKey(),
    ], $attributes));
}

function variantWith(Tenant $tenant, SocialAccount $account, ?Post $post = null): PostVariant
{
    $post ??= Post::factory()->create(['tenant_id' => $tenant->getKey()]);

    return PostVariant::factory()->create([
        'post_id' => $post->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => $account->provider->value,
        'provider_post_id' => 'post-'.uniqid(),
    ]);
}

// 5. Search / filter / pagination, tenant-isolated

it('searches by filename and alt text', function (): void {
    [$tenant] = libraryContext();

    assetIn($tenant, ['filename' => 'summer-campaign.jpg', 'alt_text' => 'Beach scene']);
    assetIn($tenant, ['filename' => 'product-shot.png', 'alt_text' => 'Studio product']);
    assetIn($tenant, ['filename' => 'unrelated.gif', 'alt_text' => 'Beach scene']);

    $results = library()->search(['search' => 'summer'], 10);

    expect($results->total())->toBe(1)
        ->and($results->first()->filename)->toBe('summer-campaign.jpg');

    $byAlt = library()->search(['search' => 'product'], 10);

    expect($byAlt->total())->toBe(1)
        ->and($byAlt->first()->filename)->toBe('product-shot.png');
});

it('filters by media type, folder and tag', function (): void {
    [$tenant] = libraryContext();

    assetIn($tenant, ['mime_type' => 'image/jpeg', 'folder' => 'launch', 'tags' => ['hero']]);
    assetIn($tenant, ['mime_type' => 'video/mp4', 'folder' => 'launch', 'tags' => ['hero', 'teaser']]);
    assetIn($tenant, ['mime_type' => 'image/png', 'folder' => 'archive', 'tags' => ['logo']]);

    expect(library()->search(['type' => 'video'], 10)->total())->toBe(1)
        ->and(library()->search(['type' => 'image'], 10)->total())->toBe(2)
        ->and(library()->search(['folder' => 'launch'], 10)->total())->toBe(2)
        ->and(library()->search(['tag' => 'teaser'], 10)->total())->toBe(1)
        ->and(library()->search(['tag' => 'hero'], 10)->total())->toBe(2);
});

it('filters by upload date', function (): void {
    [$tenant] = libraryContext();

    $old = assetIn($tenant, ['filename' => 'old.jpg']);
    $old->forceFill(['created_at' => now()->subDays(30)])->save();

    $recent = assetIn($tenant, ['filename' => 'recent.jpg']);
    $recent->forceFill(['created_at' => now()->subDay()])->save();

    expect(library()->search(['from' => now()->subDays(7)->toDateString()], 10)->total())->toBe(1)
        ->and(library()->search(['from' => now()->subDays(60)->toDateString()], 10)->total())->toBe(2)
        ->and(library()->search(['to' => now()->subDays(7)->toDateString()], 10)->total())->toBe(1);
});

it('paginates the library', function (): void {
    [$tenant] = libraryContext();

    assetIn($tenant, ['filename' => 'a.jpg']);
    assetIn($tenant, ['filename' => 'b.jpg']);
    assetIn($tenant, ['filename' => 'c.jpg']);
    assetIn($tenant, ['filename' => 'd.jpg']);
    assetIn($tenant, ['filename' => 'e.jpg']);

    $page = library()->search([], 2);

    expect($page->total())->toBe(5)
        ->and($page->perPage())->toBe(2)
        ->and($page->lastPage())->toBe(3)
        ->and($page->items())->toHaveCount(2);
});

it('keeps one tenant out of another tenants library', function (): void {
    [$tenantA] = libraryContext();
    assetIn($tenantA, ['filename' => 'tenant-a-secret.jpg']);

    TenantContext::forget();
    [$tenantB] = libraryContext();
    assetIn($tenantB, ['filename' => 'tenant-b-file.jpg']);

    $results = library()->search([], 50);

    expect($results->total())->toBe(1)
        ->and($results->first()->filename)->toBe('tenant-b-file.jpg');

    // A search term that only matches the other tenant's file finds nothing.
    expect(library()->search(['search' => 'tenant-a-secret'], 50)->total())->toBe(0);
});

it('keeps one tenant out of another tenants asset lookup', function (): void {
    [$tenantA] = libraryContext();
    $theirs = assetIn($tenantA, ['filename' => 'theirs.jpg']);

    TenantContext::forget();
    libraryContext();

    // The global scope hides the row entirely, not merely its fields.
    expect(MediaAsset::query()->find($theirs->getKey()))->toBeNull();
});

// 6. Deleting an asset in use

it('refuses to delete an asset attached to a post variant', function (): void {
    [$tenant] = libraryContext();

    $account = SocialAccount::factory()->create(['tenant_id' => $tenant->getKey()]);
    $variant = variantWith($tenant, $account);
    $asset = assetIn($tenant);

    library()->attach($variant, $asset);

    expect(library()->referenceCount((int) $asset->getKey()))->toBe(1);

    try {
        library()->delete($asset);
        $this->fail('Deleting an in-use asset should have been refused.');
    } catch (MediaUploadException $e) {
        expect($e->reason)->toBe(MediaUploadException::REASON_IN_USE)
            ->and($e->userMessage())->toContain('still attached to a post');
    }

    expect(MediaAsset::withTrashed()->find($asset->getKey()))->not->toBeNull();
});

it('deletes an asset once it is no longer attached', function (): void {
    [$tenant] = libraryContext();

    $account = SocialAccount::factory()->create(['tenant_id' => $tenant->getKey()]);
    $variant = variantWith($tenant, $account);
    $asset = assetIn($tenant, ['storage_path' => 'socialhub/media/'.$tenant->getKey().'/x.jpg']);

    Storage::disk('local')->put($asset->storage_path, 'bytes');

    library()->attach($variant, $asset);
    library()->detach($variant, $asset);

    expect(library()->referenceCount((int) $asset->getKey()))->toBe(0);

    library()->delete($asset);

    expect(MediaAsset::withTrashed()->find($asset->getKey()))->toBeNull()
        ->and(Storage::disk('local')->exists('socialhub/media/'.$tenant->getKey().'/x.jpg'))->toBeFalse();
});

it('reports which bulk deletes were refused and why', function (): void {
    [$tenant] = libraryContext();

    $account = SocialAccount::factory()->create(['tenant_id' => $tenant->getKey()]);
    $variant = variantWith($tenant, $account);

    $free = assetIn($tenant);
    $inUse = assetIn($tenant);

    library()->attach($variant, $inUse);

    $result = library()->bulkDelete([(int) $free->getKey(), (int) $inUse->getKey()]);

    expect($result['deleted'])->toBe([(int) $free->getKey()])
        ->and($result['refused'])->toHaveKey((int) $inUse->getKey())
        ->and(MediaAsset::withTrashed()->find($inUse->getKey()))->not->toBeNull();
});

it('lets a bulk delete force past an in-use asset', function (): void {
    [$tenant] = libraryContext();

    $account = SocialAccount::factory()->create(['tenant_id' => $tenant->getKey()]);
    $variant = variantWith($tenant, $account);
    $asset = assetIn($tenant);

    library()->attach($variant, $asset);

    $result = library()->bulkDelete([(int) $asset->getKey()], force: true);

    expect($result['deleted'])->toBe([(int) $asset->getKey()]);
});

it('keeps one tenant from deleting another tenants media', function (): void {
    [$tenantA] = libraryContext();
    $theirs = assetIn($tenantA, ['filename' => 'theirs.jpg']);

    TenantContext::forget();
    libraryContext();

    // Neither the row nor a soft-deleted version is reachable: the tenant scope
    // is applied to `withTrashed()` too, so there is nothing to delete.
    expect(MediaAsset::query()->find($theirs->getKey()))->toBeNull()
        ->and(MediaAsset::withTrashed()->find($theirs->getKey()))->toBeNull()
        ->and(library()->bulkDelete([(int) $theirs->getKey()])['deleted'])->toBe([]);
});

// Usage history and counters

it('reports which post variants use an asset', function (): void {
    [$tenant] = libraryContext();

    $account = SocialAccount::factory()->create(['tenant_id' => $tenant->getKey()]);
    $first = variantWith($tenant, $account);
    $second = variantWith($tenant, $account);

    $asset = assetIn($tenant);

    library()->attach($first, $asset);
    library()->attach($second, $asset);

    $usage = library()->usageSummary((int) $asset->getKey());

    expect($usage)->toHaveCount(2)
        ->and(array_column($usage, 'post_variant_id'))->toContain((int) $first->getKey())
        ->and(library()->usage((int) $asset->getKey())->total())->toBe(2);
});

it('increments usage on attach and never double counts a repeated attach', function (): void {
    [$tenant] = libraryContext();

    $account = SocialAccount::factory()->create(['tenant_id' => $tenant->getKey()]);
    $variant = variantWith($tenant, $account);
    $asset = assetIn($tenant);

    library()->attach($variant, $asset);
    library()->attach($variant, $asset);

    expect((int) $asset->refresh()->usage_count)->toBe(1)
        ->and($asset->last_used_at)->not->toBeNull();

    library()->detach($variant, $asset);

    expect((int) $asset->refresh()->usage_count)->toBe(0);
});

it('reconciles a drifted usage counter from the pivot table', function (): void {
    [$tenant] = libraryContext();

    $account = SocialAccount::factory()->create(['tenant_id' => $tenant->getKey()]);
    $variant = variantWith($tenant, $account);
    $asset = assetIn($tenant, ['usage_count' => 17]);

    library()->attach($variant, $asset);

    library()->reconcileUsageCounts();

    expect((int) $asset->refresh()->usage_count)->toBe(1);
});

// Library reporting

it('lists folders and tags for the filter controls', function (): void {
    [$tenant] = libraryContext();

    assetIn($tenant, ['folder' => 'launch', 'tags' => ['hero']]);
    assetIn($tenant, ['folder' => 'launch', 'tags' => ['hero', 'teaser']]);
    assetIn($tenant, ['folder' => null, 'tags' => ['logo']]);

    $tags = library()->tags();

    expect(library()->folders())->toBe(['launch'])
        // Most-used first; the two single-use tags tie, so only membership is asserted.
        ->and($tags[0])->toBe('hero')
        ->and(array_values(array_diff($tags, ['hero'])))->toEqualCanonicalizing(['teaser', 'logo']);
});

it('reports storage statistics grouped by media type', function (): void {
    [$tenant] = libraryContext();

    assetIn($tenant, ['mime_type' => 'image/jpeg', 'file_size' => 1000]);
    assetIn($tenant, ['mime_type' => 'image/jpeg', 'file_size' => 500]);
    assetIn($tenant, ['mime_type' => 'video/mp4', 'file_size' => 4000]);

    $stats = library()->statistics();

    expect($stats['total_assets'])->toBe(3)
        ->and($stats['total_bytes'])->toBe(5500)
        ->and($stats['by_type']['image']['count'])->toBe(2)
        ->and($stats['by_type']['video']['bytes'])->toBe(4000);
});

it('soft deletes a library row whose stored object has vanished', function (): void {
    [$tenant] = libraryContext();

    $missing = assetIn($tenant, ['storage_path' => 'socialhub/media/gone.jpg']);
    $present = assetIn($tenant, ['storage_path' => 'socialhub/media/here.jpg']);

    Storage::disk('local')->put($present->storage_path, 'bytes');

    $result = library()->pruneOrphans();

    expect($result['orphaned'])->toBe([(int) $missing->getKey()])
        ->and($result['checked'])->toBe(2)
        ->and(MediaAsset::query()->find($missing->getKey()))->toBeNull()
        ->and(MediaAsset::query()->find($present->getKey()))->not->toBeNull();
});

it('round trips an uploaded file into the library query', function (): void {
    [$tenant, $user] = libraryContext();

    $path = tempnam(sys_get_temp_dir(), 'sh-lib-').'.png';
    file_put_contents($path, MediaFixture::png());

    $asset = app(\App\Services\Media\MediaUploadService::class)->upload(
        new UploadedFile($path, 'hero.png', 'image/png', null, true),
        $user,
        folder: 'launch',
        tags: ['hero'],
    );

    expect(library()->search(['search' => 'hero'], 10)->total())->toBe(1)
        ->and(library()->search(['folder' => 'launch'], 10)->total())->toBe(1)
        ->and(library()->search(['tag' => 'hero'], 10)->total())->toBe(1)
        ->and(library()->search(['type' => 'image'], 10)->total())->toBe(1);
});
