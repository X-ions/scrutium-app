<?php

namespace App\Models;

use App\Enums\CommentSyncStatus;
use App\Enums\SocialPlatform;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    /** @use HasFactory<\Database\Factories\CommentFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'post_variant_id',
        'social_account_id',
        'parent_comment_id',
        'provider',
        'provider_comment_id',
        'author_provider_id',
        'author_username',
        'author_display_name',
        'author_avatar_url',
        'content',
        'like_count',
        'reply_count',
        'is_hidden',
        'is_deleted',
        'provider_created_at',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialPlatform::class,
            'like_count' => 'integer',
            'reply_count' => 'integer',
            'is_hidden' => 'boolean',
            'is_deleted' => 'boolean',
            'provider_created_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function postVariant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }

    public function thread(): HasMany
    {
        return $this->hasMany(self::class, 'parent_comment_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CommentReply::class);
    }

    /**
     * Sync state derived from the provider flags on the row.
     */
    public function syncStatus(): CommentSyncStatus
    {
        if ($this->is_deleted) {
            return CommentSyncStatus::Deleted;
        }

        if ($this->is_hidden) {
            return CommentSyncStatus::Failed;
        }

        return $this->synced_at === null ? CommentSyncStatus::Pending : CommentSyncStatus::Synced;
    }

    public function isThreadRoot(): bool
    {
        return $this->parent_comment_id === null;
    }

    public function authorDisplayLabel(): string
    {
        return $this->author_display_name
            ?: ($this->author_username ? '@'.$this->author_username : 'Unknown author');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false)->where('is_deleted', false);
    }

    public function scopeHidden(Builder $query): Builder
    {
        return $query->where('is_hidden', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_comment_id');
    }

    public function scopeForVariant(Builder $query, int|PostVariant $variant): Builder
    {
        $id = $variant instanceof PostVariant ? $variant->getKey() : $variant;

        return $query->where('post_variant_id', $id);
    }
}
