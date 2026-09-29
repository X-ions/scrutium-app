<?php

declare(strict_types=1);

namespace App\Services\Social\OAuth;

use App\Exceptions\Social\AuthenticationException;
use App\Services\Social\Contracts\OauthStateStore;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Database-backed OAuth state store.
 *
 * Backed by `App\Models\OauthState` (oauth_states table). The model is resolved
 * lazily and by class-string so this service has no compile-time coupling; if
 * the models layer has not shipped it yet, every call fails loudly with an
 * {@see AuthenticationException} rather than silently degrading to in-memory
 * state, which would defeat CSRF protection entirely.
 */
class DatabaseOauthStateStore implements OauthStateStore
{
    /**
     * @param  class-string|null  $modelClass
     */
    public function __construct(
        private readonly ?string $modelClass = null,
    ) {}

    /**
     * @return class-string
     */
    private function modelClass(): string
    {
        return $this->modelClass ?? 'App\\Models\\OauthState';
    }

    public function put(
        string $provider,
        string $state,
        string $codeVerifier,
        ?string $redirectUrl,
        array $scopes,
        int $tenantId,
        DateTimeInterface $expiresAt,
    ): void {
        $this->query()->create([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'state' => $state,
            'code_verifier' => $codeVerifier,
            'redirect_url' => $redirectUrl,
            'scopes' => implode(' ', $scopes),
            'expires_at' => $expiresAt,
        ]);
    }

    public function pull(string $provider, string $state): ?array
    {
        /** @var object|null $row */
        $row = $this->query()
            ->where('provider', $provider)
            ->where('state', $state)
            ->first();

        if ($row === null) {
            return null;
        }

        // Single use: the row is destroyed before the caller can act on it, so a
        // replayed callback finds nothing.
        $this->query()->whereKey($row->getKey())->delete();

        $expiresAt = $this->attribute($row, 'expires_at');

        if ($expiresAt !== null && strtotime((string) $expiresAt) < time()) {
            return null;
        }

        $scopes = $this->attribute($row, 'scopes');

        return [
            'code_verifier' => $this->attribute($row, 'code_verifier'),
            'redirect_url' => $this->attribute($row, 'redirect_url'),
            'scopes' => is_string($scopes) ? Scopes::normalise($scopes) : [],
        ];
    }

    public function pruneExpired(DateTimeInterface $before): int
    {
        return $this->query()->where('expires_at', '<', $before->format('Y-m-d H:i:s'))->delete();
    }

    /**
     * @return Builder<object>
     */
    private function query(): Builder
    {
        $class = $this->modelClass();

        if (! class_exists($class)) {
            Log::error('SocialHub OAuth state store is unavailable: the OauthState model does not exist.', [
                'model' => $class,
            ]);

            throw AuthenticationException::stateStoreUnavailable('unknown');
        }

        $model = new $class;

        if (! method_exists($model, 'newQuery')) {
            Log::error('SocialHub OAuth state store is unavailable: the OauthState model is not an Eloquent model.', [
                'model' => $class,
            ]);

            throw AuthenticationException::stateStoreUnavailable('unknown');
        }

        try {
            return $model->newQuery();
        } catch (Throwable $e) {
            Log::error('SocialHub OAuth state store could not build a query.', [
                'model' => $class,
                'error' => $e->getMessage(),
            ]);

            throw AuthenticationException::stateStoreUnavailable('unknown', $e);
        }
    }

    private function attribute(object $model, string $key): mixed
    {
        if (method_exists($model, 'getAttribute')) {
            return $model->getAttribute($key);
        }

        return $model->{$key} ?? null;
    }
}
