<?php

declare(strict_types=1);

namespace App\Http\Middleware\SocialHub;

use App\Enums\SocialPlatform;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\WebhookSignatureException;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\SocialProviderRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Verifies a provider webhook before the controller sees it.
 *
 * The single most important line in the webhook path is the raw body:
 * `$request->getContent()` returns the bytes exactly as received, and an HMAC
 * over re-encoded JSON never matches. So the raw body is captured here, put on
 * the request as `socialhub_webhook_request`, and the controller only ever
 * receives a `VerifiedWebhookRequest` it did not build itself.
 *
 * Verification itself is delegated to the provider — every platform signs
 * differently — and the provider compares with `hash_equals`. What this
 * middleware adds on top is the shape of the failure: an unverifiable request
 * gets a 401 with a JSON body and no detail about the expected signature, so a
 * prober learns nothing.
 *
 * Usage on a route:
 *   Route::post('/webhooks/{provider}', ...)->middleware(VerifyProviderWebhook::class.':facebook');
 */
class VerifyProviderWebhook
{
    public const REQUEST_ATTRIBUTE = 'socialhub_webhook_request';

    /**
     * Body size ceiling. A provider that legitimately sends megabytes of change
     * data is a surprise; 1 MiB covers every documented comment/media webhook.
     */
    private const MAX_BODY_BYTES = 1_048_576;

    public function handle(Request $request, Closure $next, ?string $providerKey = null): Response
    {
        $key = $providerKey ?? (string) $request->route('provider');

        if ($key === '' || SocialPlatform::tryFrom(strtolower($key)) === null) {
            return $this->reject('unknown_provider', 'Unknown provider.');
        }

        $key = strtolower($key);

        $rawBody = (string) $request->getContent();

        if ($rawBody === '') {
            return $this->reject('empty_body', 'The webhook body was empty.');
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->reject('body_too_large', 'The webhook body exceeds the size limit.', 413);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return $this->reject('invalid_json', 'The webhook body was not a JSON object.');
        }

        $request->request->replace($payload);

        $verified = new VerifiedWebhookRequest(
            provider: $key,
            rawBody: $rawBody,
            payload: $payload,
            headers: $this->headers($request),
            receivedAt: time(),
        );

        try {
            $registry = app(SocialProviderRegistry::class);
            $provider = $registry->get($key);

            if (! $provider->verifyWebhook($verified)) {
                return $this->reject('invalid_signature', 'Signature verification failed.');
            }
        } catch (WebhookSignatureException $e) {
            return $this->reject('invalid_signature', 'Signature verification failed.');
        } catch (ProviderNotConfiguredException) {
            return $this->reject('provider_not_configured', 'This provider is not configured.', 503);
        } catch (Throwable) {
            return $this->reject('verification_failed', 'Signature verification failed.', 503);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $verified);

        return $next($request);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if (is_array($values) && $values !== []) {
                $headers[(string) $name] = (string) $values[0];
            }
        }

        return $headers;
    }

    /**
     * The response body names the failure category but never the expected
     * signature, the configured secret, or any part of the body.
     */
    private function reject(string $code, string $message, int $status = 401): Response
    {
        return response()->json([
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ], $status);
    }
}
