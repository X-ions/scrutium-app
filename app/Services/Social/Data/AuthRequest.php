<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Inbound OAuth callback parameters, already extracted from the redirect.
 */
final readonly class AuthRequest implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, string>  $query
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $provider,
        public ?string $code = null,
        public ?string $state = null,
        public ?string $error = null,
        public ?string $errorDescription = null,
        public ?string $redirectUri = null,
        public ?string $codeVerifier = null,
        public array $query = [],
        public array $scopes = [],
    ) {}

    public function wasDenied(): bool
    {
        return $this->error !== null && $this->error !== '';
    }

    public function isComplete(): bool
    {
        return ! $this->wasDenied()
            && $this->code !== null
            && $this->state !== null;
    }

    public function withProvider(string $provider): self
    {
        return new self(
            $provider,
            $this->code,
            $this->state,
            $this->error,
            $this->errorDescription,
            $this->redirectUri,
            $this->codeVerifier,
            $this->query,
            $this->scopes,
        );
    }

    public function withState(string $state): self
    {
        return new self(
            $this->provider,
            $this->code,
            $state,
            $this->error,
            $this->errorDescription,
            $this->redirectUri,
            $this->codeVerifier,
            $this->query,
            $this->scopes,
        );
    }

    public function withScopes(array $scopes): self
    {
        return new self(
            $this->provider,
            $this->code,
            $this->state,
            $this->error,
            $this->errorDescription,
            $this->redirectUri,
            $this->codeVerifier,
            $this->query,
            $scopes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'state' => $this->state,
            'redirect_uri' => $this->redirectUri,
            'error' => $this->error,
            'error_description' => $this->errorDescription,
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
