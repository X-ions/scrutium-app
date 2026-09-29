<?php

declare(strict_types=1);

namespace App\Services\Social\Support;

use App\Exceptions\Social\TokenExpiredException;
use App\Services\Social\Data\TokenSet;
use App\Services\Social\OAuth\Scopes;
use DateTimeInterface;
use Throwable;

/**
 * Reads the stored credentials for a connected account.
 *
 * The `social_account_tokens` model is owned by the models layer, so this
 * resolver reads through public Eloquent accessors only and never assumes a
 * specific accessor exists. Access tokens are returned to the caller for an
 * outbound request and are never logged, cached, or serialised.
 */
class AccountTokenResolver
{
    /**
     * @throws TokenExpiredException when no usable access token is present
     */
    public function resolve(object $account, string $providerKey, ?int $tenantId = null): TokenSet
    {
        $tokens = $this->tokenRow($account);

        if ($tokens === null) {
            throw new TokenExpiredException($providerKey);
        }

        $accessToken = $this->stringAttribute($tokens, 'access_token');

        if ($accessToken === null || $accessToken === '') {
            throw new TokenExpiredException($providerKey);
        }

        return new TokenSet(
            accessToken: $accessToken,
            refreshToken: $this->stringAttribute($tokens, 'refresh_token'),
            idToken: $this->stringAttribute($tokens, 'id_token'),
            tokenType: $this->stringAttribute($tokens, 'token_type') ?? 'Bearer',
            expiresAt: $this->dateAttribute($tokens, 'expires_at'),
            scopes: Scopes::normalise($this->stringAttribute($tokens, 'scope') ?? ''),
            metadata: $this->arrayAttribute($tokens, 'metadata'),
        );
    }

    public function accessToken(object $account, string $providerKey): string
    {
        return $this->resolve($account, $providerKey)->accessToken;
    }

    /**
     * Locate the token record: a `token` relation when the models layer
     * provides it, otherwise a directly-set `access_token` attribute.
     */
    private function tokenRow(object $account): ?object
    {
        try {
            if (method_exists($account, 'relationLoaded') && $account->relationLoaded('token')) {
                $related = $account->getRelation('token');

                if (is_object($related)) {
                    return $related;
                }
            }

            if (method_exists($account, 'token')) {
                $related = $account->token();

                if (is_object($related)) {
                    return $related;
                }
            }

            if (method_exists($account, 'tokens')) {
                $related = $account->tokens()->first();

                if (is_object($related)) {
                    return $related;
                }
            }
        } catch (Throwable) {
            // Fall through to the direct-attribute path below.
        }

        $direct = $this->stringAttribute($account, 'access_token');

        return $direct === null ? null : $account;
    }

    private function stringAttribute(object $model, string $key): ?string
    {
        try {
            $value = method_exists($model, 'getAttribute')
                ? $model->getAttribute($key)
                : ($model->{$key} ?? null);
        } catch (Throwable) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return $value === null ? null : (string) $value;
    }

    private function arrayAttribute(object $model, string $key): array
    {
        try {
            $value = method_exists($model, 'getAttribute')
                ? $model->getAttribute($key)
                : ($model->{$key} ?? null);
        } catch (Throwable) {
            return [];
        }

        return is_array($value) ? $value : [];
    }

    private function dateAttribute(object $model, string $key): ?DateTimeInterface
    {
        try {
            $value = method_exists($model, 'getAttribute')
                ? $model->getAttribute($key)
                : ($model->{$key} ?? null);
        } catch (Throwable) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_int($value)) {
            return (new \DateTimeImmutable)->setTimestamp($value);
        }

        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);

            return $timestamp === false ? null : (new \DateTimeImmutable)->setTimestamp($timestamp);
        }

        return null;
    }
}
