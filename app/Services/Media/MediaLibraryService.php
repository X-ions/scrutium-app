<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read side of the media library: search, filtering, folders, usage history
 * and the counters that let the UI explain what a file is doing.
 *
 * Tenant isolation is inherited from the model's global scope, so there is
 * deliberately no tenant predicate in this class.
 */
final class MediaLibraryService
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly Thumbnailer $thumbnailer,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 24): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->search($filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters = [], int $perPage = 24): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->query($filters)
            ->with('uploader')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = MediaAsset::query();

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.Str::lower($search).'%';

            $query->where(function ($query) use ($like): void {
                $query->whereRaw('LOWER(filename) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(alt_text) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(folder) LIKE ?', [$like]);
            });
        }

        $type = MediaType::tryFrom((string) ($filters['type'] ?? ''));

        if ($type !== null) {
            $this->applyType($query, $type);
        }

        if (filled($filters['folder'] ?? null)) {
            $query->where('folder', (string) $filters['folder']);
        }

        if (filled($filters['tag'] ?? null)) {
            $query->whereJsonContains('tags', (string) $filters['tag']);
        }

        if (filled($filters['from'] ?? null)) {
            $query->where('created_at', '>=', (string) $filters['from']);
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('created_at', '<', (string) $filters['to'].' 23:59:59');
        }

        if (filter_var($filters['unused'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->doesntHave('postVariants');
        }

        return $query;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    private function applyType($query, MediaType $type): void
    {
        match ($type) {
            MediaType::Image => $query->where('mime_type', 'like', 'image/%'),
            MediaType::Gif => $query->where('mime_type', 'like', 'image/gif'),
            MediaType::Video => $query->where('mime_type', 'like', 'video/%'),
            MediaType::Audio => $query->where('mime_type', 'like', 'audio/%'),
            MediaType::Document => $query->where('mime_type', 'like', 'application/%'),
        };
    }

    /**
     * @return list<string>
     */
    public function folders(): array
    {
        return MediaAsset::query()
            ->whereNotNull('folder')
            ->distinct()
            ->orderBy('folder')
            ->pluck('folder')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $seen = [];

        foreach (MediaAsset::query()->pluck('tags') as $assetTags) {
            foreach ((array) $assetTags as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $seen[$tag] = true;
                }
            }
        }

        $names = array_keys($seen);
        sort($names);

        return $names;
    }

    // Reference tracking

    public function attach(\App\Models\PostVariant $variant, MediaAsset $asset): void
    {
        if ($variant->media()->whereKey($asset->getKey())->exists()) {
            return;
        }

        $variant->media()->attach($asset->getKey(), [
            'sort_order' => (int) $variant->media()->max('sort_order'),
        ]);

        $asset->markUsed();
    }

    public function detach(\App\Models\PostVariant $variant, MediaAsset $asset): void
    {
        $variant->media()->detach($asset->getKey());

        $this->reconcileUsageCounts();
    }

    public function referenceCount(int $assetId): int
    {
        return (int) MediaAsset::query()
            ->withoutGlobalScopes()
            ->whereKey($assetId)
            ->first()
            ?->postVariants()
            ->count();
    }

    /**
     * Every post variant that uses an asset.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function usage(int $assetId, int $perPage = 25)
    {
        $asset = MediaAsset::query()->findOrFail($assetId);

        return $asset->postVariants()
            ->with('post')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function usageSummary(int $assetId): array
    {
        $asset = MediaAsset::query()->findOrFail($assetId);

        return $asset->postVariants()
            ->with('post')
            ->get()
            ->map(fn (\App\Models\PostVariant $variant): array => [
                'post_variant_id' => (int) $variant->getKey(),
                'post_id' => (int) $variant->post_id,
                'post_title' => $variant->post?->title,
                'provider' => $variant->provider?->value,
                'provider_label' => $variant->provider?->label(),
                'status' => $variant->status?->value,
                'published_at' => $variant->published_at?->toIso8601String(),
                'post_url' => $variant->provider_post_url,
            ])
            ->all();
    }

    /**
     * Recount usage for every asset.
     *
     * The counter is a cache for the UI, so it is derived from the pivot table
     * rather than incremented, and can be rebuilt at any time without drifting.
     *
     * @return array{checked: int, corrected: int}
     */
    public function reconcileUsageCounts(): array
    {
        $checked = 0;
        $corrected = 0;

        MediaAsset::query()->each(function (MediaAsset $asset) use (&$checked, &$corrected): void {
            $actual = $asset->postVariants()->count();
            $checked++;

            if ((int) $asset->usage_count !== $actual) {
                $asset->forceFill(['usage_count' => $actual])->save();
                $corrected++;
            }
        });

        return ['checked' => $checked, 'corrected' => $corrected];
    }

    // Deletion

    /**
     * @throws MediaUploadException when the asset is still attached to a post.
     */
    public function delete(MediaAsset $asset, bool $force = false): void
    {
        $references = $asset->postVariants()->count();

        if ($references > 0 && ! $force) {
            throw MediaUploadException::inUse($references);
        }

        $this->storage->delete($asset->storage_disk, $asset->storage_path);

        if ($asset->thumbnail_path) {
            $this->storage->delete($asset->storage_disk, $asset->thumbnail_path);
        }

        $asset->forceDelete();
    }

    /**
     * Bulk delete that reports per-file outcomes.
     *
     * A partial result is returned rather than thrown, because "three of five
     * were in use" is a normal outcome the UI has to show, not an error.
     *
     * @param  list<int>  $ids
     * @return array{deleted: list<int>, refused: array<int, string>}
     */
    public function bulkDelete(array $ids, bool $force = false): array
    {
        $deleted = [];
        $refused = [];

        foreach (MediaAsset::query()->whereIn('id', $ids)->get() as $asset) {
            $references = $asset->postVariants()->count();

            if ($references > 0 && ! $force) {
                $refused[(int) $asset->getKey()] = sprintf(
                    'Attached to %d post variant(s). Remove it from those posts first.',
                    $references,
                );

                continue;
            }

            $this->delete($asset, $force);
            $deleted[] = (int) $asset->getKey();
        }

        return ['deleted' => $deleted, 'refused' => $refused];
    }

    /**
     * Remove assets whose bytes are no longer on the configured disk.
     *
     * The row without the file is worse than no row: every screen that renders
     * it would show a broken image with no explanation.
     *
     * @return array{checked: int, orphaned: list<int>}
     */
    public function pruneOrphans(): array
    {
        $orphaned = [];
        $checked = 0;

        MediaAsset::query()->each(function (MediaAsset $asset) use (&$orphaned, &$checked): void {
            $checked++;

            if (! $this->storage->exists($asset->storage_disk, $asset->storage_path)) {
                $orphaned[] = (int) $asset->getKey();
                $asset->forceDelete();
            }
        });

        return ['checked' => $checked, 'orphaned' => $orphaned];
    }

    /**
     * Regenerate any missing thumbnails.
     *
     * @return array{generated: int, skipped: int}
     */
    public function rebuildThumbnails(): array
    {
        $generated = 0;
        $skipped = 0;

        MediaAsset::query()->whereNull('thumbnail_path')->each(function (MediaAsset $asset) use (&$generated, &$skipped): void {
            $path = $this->thumbnailer->make(new MediaAssetTarget(
                id: (int) $asset->getKey(),
                disk: (string) $asset->storage_disk,
                path: (string) $asset->storage_path,
                mimeType: (string) $asset->mime_type,
                thumbnailName: (string) $asset->stored_filename,
                tenantId: $asset->tenant_id,
            ));

            if ($path === null) {
                $skipped++;

                return;
            }

            $asset->forceFill(['thumbnail_path' => $path])->save();
            $generated++;
        });

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * @return array<string, mixed>
     */
    public function statistics(): array
    {
        $byType = [];

        foreach (MediaAsset::query()->get(['mime_type', 'file_size']) as $asset) {
            $type = MediaType::fromMimeType((string) $asset->mime_type)->value;

            $byType[$type] ??= ['count' => 0, 'bytes' => 0];

            $byType[$type]['count']++;
            $byType[$type]['bytes'] += (int) $asset->file_size;
        }

        ksort($byType);

        return [
            'total_assets' => (int) MediaAsset::query()->count(),
            'total_bytes' => (int) MediaAsset::query()->sum('file_size'),
            'by_type' => $byType,
            'folders' => count($this->folders()),
            'tags' => count($this->tags()),
        ];
    }

    public function totalBytes(): int
    {
        return (int) MediaAsset::query()->sum('file_size');
    }

    public function remainingBytes(int $tenantId): ?int
    {
        $tenant = Tenant::query()->find($tenantId);
        $limitMb = (int) ($tenant?->storage_limit_mb ?? 0);

        return $limitMb > 0 ? max(0, $limitMb * 1024 * 1024 - $this->totalBytes()) : null;
    }

    public function isInUse(MediaAsset $asset): bool
    {
        return $asset->postVariants()->exists();
    }

    public function download(MediaAsset $asset): BinaryFileResponse
    {
        $absolute = $this->storage->absolutePath((string) $asset->storage_disk, (string) $asset->storage_path);

        return response()->download($absolute, (string) $asset->filename);
    }

    /**
     * Where a user who can see the asset gets its bytes.
     */
    public function urlFor(MediaAsset $asset): ?string
    {
        return $this->storage->url((string) $asset->storage_disk, (string) $asset->storage_path);
    }

    public function thumbnailUrlFor(MediaAsset $asset): ?string
    {
        if (! $asset->thumbnail_path) {
            return $this->urlFor($asset);
        }

        return $this->storage->url((string) $asset->storage_disk, (string) $asset->thumbnail_path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function usageForView(MediaAsset $asset): array
    {
        return $this->usageSummary((int) $asset->getKey());
    }

    public function markUsed(MediaAsset $asset): void
    {
        $asset->markUsed();
    }
}
