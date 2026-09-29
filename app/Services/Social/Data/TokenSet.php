<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Provider credentials for one connected account.
 *
 * `toArray()` redacts every secret value: only presence flags and metadata are
 * exposed, so a DTO can never be serialised into a log line or an API resource.
 */
final readonly class TokenSet implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?string $idToken = null,
        public string $tokenType = 'Bearer',
        public ?int $expiresIn = null,
        public ?DateTimeInterface $expiresAt = null,
        public array $scopes = [],
        public array $metadata = [],
    ) {}

    public function isExpired(?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $reference = $now ?? time();

        return $this->expiresAt->getTimestamp() <= $reference;
    }

    public function expiresWithin(int $seconds, ?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt->getTimestamp() <= ($now ?? time()) + $seconds;
    }

    public function scopeList(): array
    {
        return $this->scopes;
    }

    /**
     * Safe for logs and API resources: no token material, only presence flags.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token_type' => $this->tokenType,
            'has_access_token' => $this->accessToken !== '',
            'has_refresh_token' => $this->refreshToken !== null && $this->refreshToken !== '',
            'has_id_token' => $this->idToken !== null && $this->idToken !== '',
            'expires_in' => $this->expiresIn,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
            'scopes' => $this->scopes,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
