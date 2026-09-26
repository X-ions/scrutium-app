<?php

namespace App\Models;

use App\Enums\DeliverableStatus;
use App\Enums\DeliverableType;
use App\Enums\Platform;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Deliverable extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'influencer_id',
        'verified_by',
        'title',
        'type',
        'platform',
        'contracted_units',
        'delivered_units',
        'fee',
        'status',
        'due_at',
        'posted_at',
        'verified_at',
        'evidence_path',
        'rejection_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DeliverableType::class,
            'platform' => Platform::class,
            'status' => DeliverableStatus::class,
            'contracted_units' => 'integer',
            'delivered_units' => 'integer',
            'fee' => 'decimal:2',
            'due_at' => 'datetime',
            'posted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(DeliverableEvent::class)->latest('id');
    }

    public function contentPost(): HasOne
    {
        return $this->hasOne(ContentPost::class);
    }

    public function statusEnum(): DeliverableStatus
    {
        $status = $this->status;

        return $status instanceof DeliverableStatus ? $status : DeliverableStatus::Pending;
    }

    public function isOverdue(): bool
    {
        if (! $this->due_at) {
            return false;
        }

        return $this->due_at->isPast()
            && ! in_array($this->statusEnum(), [DeliverableStatus::Approved, DeliverableStatus::Rejected], true);
    }

    /**
     * Evidence has been uploaded and the deliverable is awaiting verification.
     */
    public function markSubmitted(?string $evidencePath = null, ?User $actor = null): self
    {
        $from = $this->statusEnum();

        $this->status = DeliverableStatus::Submitted;
        $this->posted_at ??= now();
        $this->rejection_reason = null;

        if ($evidencePath) {
            $this->evidence_path = $evidencePath;
        }

        $this->save();

        $this->logEvent('submitted', $from, $actor);

        return $this;
    }

    public function approve(?User $actor = null): self
    {
        $from = $this->statusEnum();

        $this->status = DeliverableStatus::Approved;
        $this->verified_at = now();
        $this->verified_by = $actor?->id;
        $this->rejection_reason = null;
        $this->delivered_units = max((int) $this->delivered_units, (int) $this->contracted_units);

        $this->save();

        $this->logEvent('approved', $from, $actor);

        return $this;
    }

    public function reject(string $reason, ?User $actor = null): self
    {
        $from = $this->statusEnum();

        $this->status = DeliverableStatus::Rejected;
        $this->verified_at = null;
        $this->verified_by = $actor?->id;
        $this->rejection_reason = $reason;

        $this->save();

        $this->logEvent('rejected', $from, $actor, $reason);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function logEvent(string $action, ?DeliverableStatus $from, ?User $actor, ?string $note = null, array $meta = []): DeliverableEvent
    {
        return $this->auditEvents()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $this->statusEnum()->value,
            'note' => $note,
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (DeliverableStatus $status) => $status->value,
            DeliverableStatus::outstanding(),
        ));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function scopeInStatus(Builder $query, DeliverableStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
