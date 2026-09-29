<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Result of a completed authorization-code exchange.
 *
 * `authorizationUrl` is populated on the "begin" leg of the flow so the caller
 * can redirect the browser; it is null once tokens have been obtained.
 */
final readonly class AuthSession implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $profile
     */
    public function __construct(
        public string $provider,
        public string $authorizationUrl,
        public string $state,
        public ?string $codeVerifier = null,
        public ?string $redirectUri = null,
        public array $scopes = [],
        public ?TokenSet $tokens = null,
        public array $profile = [],
        public ?DateTimeInterface $stateExpiresAt = null,
    ) {}

    public function isCompleted(): bool
    {
        return $this->tokens !== null;
    }

    public function usesPkce(): bool
    {
        return $this->codeVerifier !== null && $this->codeVerifier !== '';
    }

    public function hasAuthorizationUrl(): bool
    {
        return $this->authorizationUrl !== '' && str_contains($this->authorizationUrl, '?');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'authorization_url' => $this->authorizationUrl,
            'state' => $this->state,
            'code_verifier' => $this->codeVerifier,
            'redirect_uri' => $this->redirectUri,
            'scopes' => $this->scopes,
            'tokens' => $this->tokens?->toArray(),
            'profile' => $this->profile,
            'state_expires_at' => $this->stateExpiresAt instanceof DateTimeInterface
                ? $this->stateExpiresAt->format(DateTimeInterface::ATOM)
                : null,
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
