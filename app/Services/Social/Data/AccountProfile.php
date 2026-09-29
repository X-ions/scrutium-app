<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Normalised identity of a connected account (page, channel, profile, or user).
 */
final readonly class AccountProfile implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $grantedScopes
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $providerAccountId,
        public ?string $username = null,
        public ?string $displayName = null,
        public ?string $avatarUrl = null,
        public ?string $accountType = null,
        public ?string $profileUrl = null,
        public ?int $followerCount = null,
        public array $grantedScopes = [],
        public ?DateTimeInterface $connectedAt = null,
        public array $raw = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_account_id' => $this->providerAccountId,
            'username' => $this->username,
            'display_name' => $this->displayName,
            'avatar_url' => $this->avatarUrl,
            'account_type' => $this->accountType,
            'profile_url' => $this->profileUrl,
            'follower_count' => $this->followerCount,
            'granted_scopes' => $this->grantedScopes,
            'connected_at' => $this->connectedAt?->format(DateTimeInterface::ATOM),
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
