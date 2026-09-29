<?php

namespace App\Models;

use App\Enums\MediaType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    /** @use HasFactory<\Database\Factories\MediaAssetFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'filename',
        'stored_filename',
        'mime_type',
        'file_size',
        'width',
        'height',
        'duration',
        'storage_disk',
        'storage_path',
        'thumbnail_path',
        'alt_text',
        'tags',
        'folder',
        'metadata',
        'usage_count',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'decimal:3',
            'usage_count' => 'integer',
            'tags' => 'array',
            'metadata' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function postVariants(): BelongsToMany
    {
        return $this->belongsToMany(PostVariant::class, 'post_media')
            ->withPivot(['sort_order']);
    }

    public function mediaType(): MediaType
    {
        return MediaType::fromMimeType($this->mime_type);
    }

    public function fileSizeForHumans(): string
    {
        $bytes = (int) $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 2).' '.$units[$power];
    }

    public function markUsed(): self
    {
        $this->usage_count = (int) $this->usage_count + 1;
        $this->last_used_at = now();
        $this->save();

        return $this;
    }

    public function scopeOfType(Builder $query, MediaType $type): Builder
    {
        return $query->where(function (Builder $query) use ($type) {
            foreach (self::mimePrefixesFor($type) as $prefix) {
                $query->orWhere('mime_type', 'like', $prefix.'%');
            }
        });
    }

    public function scopeImages(Builder $query): Builder
    {
        return $query->ofType(MediaType::Image);
    }

    public function scopeVideos(Builder $query): Builder
    {
        return $query->ofType(MediaType::Video);
    }

    public function scopeInFolder(Builder $query, ?string $folder): Builder
    {
        return $query->where('folder', $folder);
    }

    /**
     * @return list<string>
     */
    private static function mimePrefixesFor(MediaType $type): array
    {
        return match ($type) {
            MediaType::Image, MediaType::Gif => ['image/'],
            MediaType::Video => ['video/'],
            MediaType::Audio => ['audio/'],
            MediaType::Document => ['application/', 'text/'],
        };
    }
}
