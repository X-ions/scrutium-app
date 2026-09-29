<?php

declare(strict_types=1);

namespace App\Services\Social\Providers\Concerns;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Reads a connected account's model safely, without assuming the attribute
 * exists.
 *
 * The social account, token, and variant models are owned by another layer, so
 * a provider must never reach into them directly. Every read goes through
 * `getAttribute()` when the model offers it and degrades to a null read rather
 * than throwing, so a schema change surfaces as a clear "not supported" error
 * rather than a TypeError mid-publish.
 */
trait ReadsConnectedAccount
{
    protected function attribute(object $model, string $key): mixed
    {
        try {
            return method_exists($model, 'getAttribute')
                ? $model->getAttribute($key)
                : ($model->{$key} ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataOf(object $account): array
    {
        $metadata = $this->attribute($account, 'metadata');

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @return list<string>
     */
    protected function grantedScopesOf(object $account): array
    {
        $scopes = $this->metadataOf($account)['granted_scopes'] ?? null;

        return is_array($scopes) ? array_values(array_map('strval', $scopes)) : [];
    }

    /**
     * Reads a date from a decoded provider payload or from a model attribute,
     * so the same call site works for both response data and Eloquent models.
     */
    protected function readDate(array|object $source, string $key): ?DateTimeInterface
    {
        $value = $source instanceof Model || ! is_array($source)
            ? $this->attribute($source, $key)
            : ($source[$key] ?? null);

        return $this->toDate($value);
    }

    protected function toDate(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_int($value)) {
            return (new DateTimeImmutable)->setTimestamp($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : (new DateTimeImmutable)->setTimestamp($timestamp);
    }

    protected function accountIdOf(object $account): string|int|null
    {
        return $this->attribute($account, 'provider_account_id');
    }

    protected function tenantIdOf(object $account): int|string|null
    {
        $tenantId = $this->attribute($account, 'tenant_id');

        return is_int($tenantId) || is_string($tenantId) ? $tenantId : null;
    }
}
