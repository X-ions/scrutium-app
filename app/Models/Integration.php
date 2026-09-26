<?php

namespace App\Models;

use App\Enums\IntegrationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'provider',
        'name',
        'status',
        'credentials',
        'auto_verify',
        'last_synced_at',
        'last_error',
    ];

    /**
     * Integration credentials are encrypted at rest through the model cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntegrationStatus::class,
            'credentials' => 'encrypted:array',
            'auto_verify' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function statusEnum(): IntegrationStatus
    {
        $status = $this->status;

        return $status instanceof IntegrationStatus ? $status : IntegrationStatus::Disconnected;
    }

    public function isHealthy(): bool
    {
        return $this->statusEnum()->isHealthy();
    }

    public function markConnected(): self
    {
        $this->status = IntegrationStatus::Connected;
        $this->last_error = null;
        $this->last_synced_at = now();
        $this->save();

        return $this;
    }

    public function markDegraded(string $error): self
    {
        $this->status = IntegrationStatus::Degraded;
        $this->last_error = $error;
        $this->save();

        return $this;
    }

    public function markDisconnected(string $error): self
    {
        $this->status = IntegrationStatus::Disconnected;
        $this->last_error = $error;
        $this->save();

        return $this;
    }

    /**
     * Credentials the UI is allowed to show — secrets are always masked.
     *
     * @return array<string, mixed>
     */
    public function maskedCredentials(): array
    {
        $credentials = $this->credentials;

        if (! is_array($credentials)) {
            return [];
        }

        $masked = [];
        foreach ($credentials as $key => $value) {
            $masked[$key] = preg_match('/secret|token|key|password/i', (string) $key)
                ? '••••••••'
                : $value;
        }

        return $masked;
    }

    public function scopeHealthy(Builder $query): Builder
    {
        return $query->where('status', IntegrationStatus::Connected->value);
    }
}
