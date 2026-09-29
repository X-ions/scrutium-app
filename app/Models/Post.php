<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    /** @use HasFactory<\Database\Factories\PostFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'campaign_id',
        'title',
        'status',
        'tags',
        'notes',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'tags' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }

    public function statusEnum(): PostStatus
    {
        return $this->status instanceof PostStatus ? $this->status : PostStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->statusEnum() === PostStatus::Published;
    }

    /**
     * Post is considered live once at least one variant reached a provider.
     */
    public function hasPublishedVariant(): bool
    {
        return $this->variants()->where('status', PostVariantStatus::Published->value)->exists();
    }

    public function scopeInStatus(Builder $query, PostStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn (PostStatus $status) => $status->value, $statuses));
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->inStatus(PostStatus::Published);
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->inStatus(PostStatus::Draft);
    }

    public function scopeForCampaign(Builder $query, int|Campaign|null $campaign): Builder
    {
        $id = $campaign instanceof Campaign ? $campaign->getKey() : $campaign;

        return $query->where('campaign_id', $id);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('title', 'like', "%{$term}%")->orWhere('notes', 'like', "%{$term}%");
        });
    }
}
