<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Short-lived PKCE state used while completing an OAuth authorisation.
 */
class OauthState extends Model
{
    /** @use HasFactory<\Database\Factories\OauthStateFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'provider',
        'state',
        'code_verifier',
        'redirect_url',
        'scopes',
        'expires_at',
    ];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialPlatform::class,
            'code_verifier' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @var list<string>
     */
    protected $hidden = [
        'code_verifier',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return list<string>
     */
    public function requestedScopes(): array
    {
        return array_values(array_filter(preg_split('/\s+/', (string) $this->scopes) ?: []));
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeForProvider(Builder $query, SocialPlatform $provider): Builder
    {
        return $query->where('provider', $provider->value);
    }
}
