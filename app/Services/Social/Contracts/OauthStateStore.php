<?php

declare(strict_types=1);

namespace App\Services\Social\Contracts;

/**
 * Persistence contract for single-use OAuth state rows.
 *
 * The database-backed implementation is provided by the models layer
 * (`App\Models\OauthState`); the interface exists so the OAuth coordinator has
 * no direct DB coupling and cannot silently fall back to in-memory state.
 */
interface OauthStateStore
{
    /**
     * Persist a pending authorization state with an absolute expiry.
     *
     * @param  list<string>  $scopes
     */
    public function put(
        string $provider,
        string $state,
        string $codeVerifier,
        ?string $redirectUrl,
        array $scopes,
        int $tenantId,
        \DateTimeInterface $expiresAt,
    ): void;

    /**
     * Atomically fetch and delete the state row. Returns null when the state is
     * unknown, expired, or already consumed — the caller must not distinguish.
     *
     * @return array{code_verifier: ?string, redirect_url: ?string, scopes: list<string>}|null
     */
    public function pull(string $provider, string $state): ?array;

    /**
     * Delete any rows for a provider that expired before the given moment.
     */
    public function pruneExpired(\DateTimeInterface $before): int;
}
