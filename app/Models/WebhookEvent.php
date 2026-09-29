<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw provider webhook deliveries, used to make ingestion idempotent.
 */
class WebhookEvent extends Model
{
    /** @use HasFactory<\Database\Factories\WebhookEventFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'provider',
        'event_id',
        'event_type',
        'payload',
        'processed',
        'processed_at',
        'error_message',
    ];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialPlatform::class,
            'payload' => 'array',
            'processed' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function markProcessed(): self
    {
        $this->processed = true;
        $this->processed_at = now();
        $this->error_message = null;
        $this->save();

        return $this;
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->where('processed', false);
    }

    public function scopeForProvider(Builder $query, SocialPlatform $provider): Builder
    {
        return $query->where('provider', $provider->value);
    }
}
