<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'generated_by',
        'title',
        'type',
        'status',
        'version',
        'period_start',
        'period_end',
        'frozen_at',
        'payload',
        'file_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'version' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'frozen_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function statusEnum(): ReportStatus
    {
        $status = $this->status;

        return $status instanceof ReportStatus ? $status : ReportStatus::Draft;
    }

    /**
     * Lock the report as an immutable snapshot for the period it covers.
     */
    public function freeze(?User $actor = null): self
    {
        if ($this->statusEnum()->isImmutable()) {
            return $this;
        }

        $this->status = ReportStatus::Frozen;
        $this->frozen_at = now();

        if ($actor) {
            $this->generated_by = $actor->id;
        }

        $this->save();

        return $this;
    }

    public function publish(): self
    {
        $this->status = ReportStatus::Published;
        $this->frozen_at ??= now();
        $this->save();

        return $this;
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', ReportStatus::Draft->value);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ReportStatus::Published->value);
    }

    public function periodLabel(): string
    {
        if (! $this->period_start && ! $this->period_end) {
            return 'All time';
        }

        $start = $this->period_start?->format('M j, Y') ?? '—';
        $end = $this->period_end?->format('M j, Y') ?? '—';

        return $start.' – '.$end;
    }
}
