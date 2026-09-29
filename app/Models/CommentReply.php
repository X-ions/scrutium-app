<?php

namespace App\Models;

use App\Enums\CommentSyncStatus;
use App\Enums\SocialPlatform;
use App\Models\Concerns\ScopedToTenantThroughRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReply extends Model
{
    /** @use HasFactory<\Database\Factories\CommentReplyFactory> */
    use HasFactory, ScopedToTenantThroughRelation;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'comment_id',
        'user_id',
        'social_account_id',
        'provider',
        'content',
        'provider_reply_id',
        'status',
        'error_message',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialPlatform::class,
            'status' => CommentSyncStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    protected static function tenantScopedRelation(): string
    {
        return 'comment';
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function statusEnum(): CommentSyncStatus
    {
        return $this->status instanceof CommentSyncStatus ? $this->status : CommentSyncStatus::Pending;
    }

    public function wasSent(): bool
    {
        return $this->statusEnum() === CommentSyncStatus::Sent;
    }

    public function scopeInStatus(Builder $query, CommentSyncStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn (CommentSyncStatus $status) => $status->value, $statuses));
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->inStatus(CommentSyncStatus::Pending);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->inStatus(CommentSyncStatus::Failed);
    }
}
