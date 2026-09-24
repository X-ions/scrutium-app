<?php

namespace App\Models;

use App\Enums\DeliverableStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail entry for a deliverable's lifecycle.
 */
class DeliverableEvent extends Model
{
    /** @use HasFactory<\Database\Factories\DeliverableEventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'deliverable_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'note',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => DeliverableStatus::class,
            'to_status' => DeliverableStatus::class,
            'meta' => 'array',
        ];
    }

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'created' => 'Created',
            'submitted' => 'Evidence submitted',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => ucfirst(str_replace('_', ' ', (string) $this->action)),
        };
    }
}
