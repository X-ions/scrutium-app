<?php

namespace App\Models;

use App\Enums\CampaignStage;
use App\Enums\CampaignStatus;
use App\Enums\DeliverableStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'owner_id',
        'name',
        'slug',
        'objective',
        'brief',
        'stage',
        'status',
        'budget_total',
        'budget_spent',
        'currency',
        'starts_at',
        'ends_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => CampaignStage::class,
            'status' => CampaignStatus::class,
            'budget_total' => 'decimal:2',
            'budget_spent' => 'decimal:2',
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function influencers(): BelongsToMany
    {
        return $this->belongsToMany(Influencer::class)
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

    public function budgetRemaining(): float
    {
        return (float) $this->budget_total - (float) $this->budget_spent;
    }

    /**
     * Share of the budget already committed, as a percentage.
     */
    public function budgetUtilisation(): float
    {
        $total = (float) $this->budget_total;

        if ($total <= 0) {
            return 0.0;
        }

        return round(((float) $this->budget_spent / $total) * 100, 2);
    }

    /**
     * Share of contracted deliverables that have been approved.
     */
    public function attainmentPercent(): float
    {
        $total = $this->deliverables()->count();

        if ($total === 0) {
            return 0.0;
        }

        $approved = $this->deliverables()
            ->where('status', DeliverableStatus::Approved->value)
            ->count();

        return round(($approved / $total) * 100, 2);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (CampaignStatus $status) => $status->value,
            array_filter(CampaignStatus::cases(), fn (CampaignStatus $s) => $s->isOpen()),
        ));
    }

    public function scopeInStage(Builder $query, CampaignStage $stage): Builder
    {
        return $query->where('stage', $stage->value);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('objective', 'like', "%{$term}%");
        });
    }
}
