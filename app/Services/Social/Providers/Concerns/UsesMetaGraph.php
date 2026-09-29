<?php

declare(strict_types=1);

namespace App\Services\Social\Providers\Concerns;

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\WebhookSignatureException;
use App\Services\Social\Data\UserFacingError;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\ProviderHttpClient;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The parts of the Meta Graph API that Facebook and Instagram share.
 *
 * Both platforms authenticate the same way (a Facebook Login user token), speak
 * the same REST dialect against `graph.facebook.com/{version}`, return the same
 * `{ "error": { "code", "message", ... } }` envelope, and sign webhooks with
 * `X-Hub-Signature-256` over the raw body using the app secret. Only the
 * endpoints differ, so the endpoint-specific code stays in each provider.
 */
trait UsesMetaGraph
{
    /**
     * A Graph request scoped to the account's tenant, authenticated with a
     * bearer token. Pass a token explicitly when the object being addressed
     * publishes under a different credential than the stored one.
     */
    protected function graph(object $account, ?string $accessToken = null): ProviderHttpClient
    {
        return $this->http
            ->withAccessToken($accessToken ?? $this->graphTokenFor($account))
            ->withTenant($this->tenantIdOf($account))
            ->withHeader('X-Api-Version', $this->graphVersion());
    }

    protected function oauthHttp(): ProviderHttpClient
    {
        return $this->http->withoutThrottle();
    }

    protected function graphVersion(): string
    {
        return (string) config('socialhub.providers.'.$this->providerKey().'.oauth.graph_version', 'v23.0');
    }

    protected function graphUrl(string $path): string
    {
        return sprintf('https://graph.facebook.com/%s/%s', $this->graphVersion(), ltrim($path, '/'));
    }

    protected function graphTokenFor(object $account): string
    {
        return $this->tokens->resolve($account, $this->providerKey())->accessToken;
    }

    /**
     * @throws WebhookSignatureException
     * @throws ProviderNotConfiguredException
     */
    protected function verifyMetaSignature(VerifiedWebhookRequest $request): void
    {
        $secret = $this->metaWebhookSecret();

        if ($secret === '') {
            throw new ProviderNotConfiguredException(
                $this->providerKey(),
                'the Meta app secret used for webhook signatures is not configured',
            );
        }

        $signature = $request->header('X-Hub-Signature-256');

        if ($signature === null || $signature === '') {
            throw new WebhookSignatureException($this->providerKey(), WebhookSignatureException::REASON_MISSING_SIGNATURE);
        }

        if (! hash_equals('sha256='.hash_hmac('sha256', $request->rawBody, $secret), $signature)) {
            throw new WebhookSignatureException($this->providerKey(), WebhookSignatureException::REASON_SIGNATURE);
        }

        $this->assertMetaTimestampWithinTolerance($request);
    }

    /**
     * @throws WebhookSignatureException
     */
    protected function assertMetaTimestampWithinTolerance(VerifiedWebhookRequest $request): void
    {
        $timestamp = $request->header('X-Hub-Signature-Timestamp') ?? $request->header('X-Facebook-Delivery-Timestamp');

        if ($timestamp === null || ! ctype_digit($timestamp)) {
            return;
        }

        $tolerance = (int) config('socialhub.webhooks.tolerance_seconds', 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new WebhookSignatureException($this->providerKey(), WebhookSignatureException::REASON_TIMESTAMP);
        }
    }

    protected function metaWebhookSecret(): string
    {
        $secret = config('socialhub.providers.'.$this->providerKey().'.oauth.webhook_secret');

        return is_string($secret) ? $secret : '';
    }

    /**
     * Deauthorize through the permissions edge, swallowing failures so a
     * platform-side error never blocks the local disconnect.
     */
    protected function deauthorizeQuietly(object $account): void
    {
        try {
            $this->graph($account)->delete($this->graphUrl('me/permissions'));
        } catch (Throwable $e) {
            logger()->warning('SocialHub provider disconnect call failed; continuing with the local disconnect.', [
                'provider' => $this->providerKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Throws before any request when the provider's env credentials are absent.
     *
     * @param  array<string, string>  $credentialToEnvKey  config key => .env name
     *
     * @throws ProviderNotConfiguredException
     */
    protected function assertCredentialsPresent(array $credentialToEnvKey): void
    {
        $credentials = (array) config('socialhub.providers.'.$this->providerKey().'.credentials', []);

        $missing = [];

        foreach ($credentialToEnvKey as $configKey => $envKey) {
            $value = $credentials[$configKey] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $envKey;
            }
        }

        if ($missing === []) {
            return;
        }

        throw new ProviderNotConfiguredException(
            $this->providerKey(),
            sprintf('missing %s in .env', implode(', ', $missing)),
        );
    }

    protected function assertSuccessful(Response $response, string $operation): void
    {
        $this->assertNotRateLimited($response);

        if (! $response->successful()) {
            $this->logProviderFailure($operation, $response);

            throw $this->apiException($response);
        }
    }

    protected function throwPaginationFailure(Response $response): ProviderApiException
    {
        return $this->apiException($response);
    }

    protected function apiException(Response $response, ?UserFacingError $userFacingError = null): ProviderApiException
    {
        return $this->buildApiException($response, $userFacingError);
    }
}
