<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenantThroughRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    /** @use HasFactory<\Database\Factories\PostMediaFactory> */
    use HasFactory, ScopedToTenantThroughRelation;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_variant_id',
        'media_asset_id',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function tenantScopedRelation(): string
    {
        return 'postVariant.post';
    }

    public function postVariant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
