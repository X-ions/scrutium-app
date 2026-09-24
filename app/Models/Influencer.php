<?php

namespace App\Models;

use App\Enums\InfluencerTier;
use App\Enums\Platform;
use App\Enums\VettingStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Influencer extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'handle',
        'platform',
        'full_name',
        'email',
        'country',
        'avatar_url',
        'followers',
        'engagement_rate',
        'pulse_score',
        'tier',
        'vetting_status',
        'notes',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'tier' => InfluencerTier::class,
            'vetting_status' => VettingStatus::class,
            'followers' => 'integer',
            'engagement_rate' => 'decimal:2',
            'pulse_score' => 'decimal:2',
            'last_synced_at' => 'datetime',
        ];
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)
            ->withPivot(['role', 'status', 'agreed_fee', 'notes'])
            ->withTimestamps();
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    public function contentPosts(): HasMany
    {
        return $this->hasMany(ContentPost::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(InfluencerScore::class);
    }

    public function latestScore(): HasOne
    {
        return $this->hasOne(InfluencerScore::class)->latestOfMany('computed_at');
    }

    public function displayName(): string
    {
        return $this->full_name ?: '@'.ltrim((string) $this->handle, '@');
    }

    public function initials(): string
    {
        $source = $this->full_name ?: (string) $this->handle;
        $parts = preg_split('/[\s._-]+/', trim($source)) ?: [];

        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }

    public function formattedFollowers(): string
    {
        $followers = (int) $this->followers;

        return match (true) {
            $followers >= 1_000_000 => number_format($followers / 1_000_000, 1).'M',
            $followers >= 1_000 => number_format($followers / 1_000, 1).'K',
            default => (string) $followers,
        };
    }

    public function isBookable(): bool
    {
        return $this->vetting_status instanceof VettingStatus
            ? $this->vetting_status->isBookable()
            : false;
    }

    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('vetting_status', VettingStatus::Vetted->value);
    }

    public function scopeOnPlatform(Builder $query, Platform $platform): Builder
    {
        return $query->where('platform', $platform->value);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('handle', 'like', "%{$term}%")
                ->orWhere('full_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    /**
     * Refresh the derived tier from the current follower count.
     */
    public function syncTier(): self
    {
        $this->tier = InfluencerTier::fromFollowers((int) $this->followers);

        return $this;
    }
}
