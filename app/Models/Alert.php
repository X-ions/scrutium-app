<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Alert extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'assigned_to',
        'type',
        'severity',
        'status',
        'title',
        'message',
        'subject_type',
        'subject_id',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => AlertSeverity::class,
            'status' => AlertStatus::class,
            'resolved_at' => 'datetime',
            'subject_id' => 'integer',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The record this alert refers to (deliverable, content post, integration…).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function severityEnum(): AlertSeverity
    {
        $severity = $this->severity;

        return $severity instanceof AlertSeverity ? $severity : AlertSeverity::Info;
    }

    public function statusEnum(): AlertStatus
    {
        $status = $this->status;

        return $status instanceof AlertStatus ? $status : AlertStatus::Open;
    }

    public function acknowledge(?User $actor = null): self
    {
        if ($this->statusEnum() !== AlertStatus::Open) {
            return $this;
        }

        $this->status = AlertStatus::Acknowledged;

        if ($actor && ! $this->assigned_to) {
            $this->assigned_to = $actor->id;
        }

        $this->save();

        return $this;
    }

    public function resolve(?User $actor = null): self
    {
        $this->status = AlertStatus::Resolved;
        $this->resolved_at = now();

        if ($actor && ! $this->assigned_to) {
            $this->assigned_to = $actor->id;
        }

        $this->save();

        return $this;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', AlertStatus::Open->value);
    }

    public function scopeEscalated(Builder $query): Builder
    {
        return $query->whereIn('severity', array_map(
            fn (AlertSeverity $severity) => $severity->value,
            AlertSeverity::escalation(),
        ));
    }

    /**
     * Order by the human weight of each severity rather than alphabetically.
     */
    public function scopeMostSevereFirst(Builder $query): Builder
    {
        return $query->orderByRaw(
            "case severity when 'critical' then 5 when 'high' then 4 when 'medium' then 3 when 'low' then 2 else 1 end desc"
        );
    }
}
