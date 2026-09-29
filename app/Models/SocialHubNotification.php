<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialHubNotification extends Model
{
    /** @use HasFactory<\Database\Factories\SocialHubNotificationFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'socialhub_notifications';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'action_url',
        'is_read',
        'read_at',
        'priority',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBroadcast(): bool
    {
        return $this->user_id === null;
    }

    public function markRead(): self
    {
        if (! $this->is_read) {
            $this->is_read = true;
            $this->read_at = now();
            $this->save();
        }

        return $this;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function scopeForUser(Builder $query, int|User|null $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->where('user_id', $id);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
