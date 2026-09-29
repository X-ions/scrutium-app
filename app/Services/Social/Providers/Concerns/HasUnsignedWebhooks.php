<?php

declare(strict_types=1);

namespace App\Services\Social\Providers\Concerns;

use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Services\Social\Data\UserFacingError;
use App\Services\Social\Data\VerifiedWebhookRequest;

/**
 * For platforms whose inbound notifications carry no signature at all.
 *
 * YouTube (Pub/SubHubbub), X, LinkedIn, and Pinterest deliver no HMAC header, so
 * there is nothing to verify with. Returning a fabricated "verified" boolean
 * would make an unauthenticated caller look authenticated, so verification
 * refuses instead: the network simply does not advertise the `webhooks`
 * capability and the composer should not have wired one up.
 */
trait HasUnsignedWebhooks
{
    /**
     * @throws UnsupportedCapabilityException always
     */
    public function verifyWebhook(VerifiedWebhookRequest $request): bool
    {
        throw new UnsupportedCapabilityException(
            'webhooks',
            $this->providerKey(),
            UserFacingError::make(
                'unsupported_capability',
                sprintf('%s does not deliver signed webhooks, so an incoming notification cannot be authenticated and is not accepted.', $this->platformName()),
                sprintf('Provider "%s" sends no webhook signature header to verify.', $this->providerKey()),
                false,
                'Use the scheduled analytics sync for this network instead of relying on inbound notifications.',
                ['provider' => $this->providerKey()],
            ),
        );
    }

    /**
     * The body shape is still normalised for completeness, so a caller that
     * happens to receive a notification gets the internal event shape. It is
     * never reached through a verified path, because `verifyWebhook()` refuses
     * first.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeWebhook(array $payload): array
    {
        $data = $payload['data'] ?? [];

        // Google and Pinterest put the notification id at the top level and the
        // subject of the event under `data`; X nests both. Preferring the
        // top-level id keeps the event id distinct from the content id.
        $eventId = $payload['id'] ?? (is_array($data) ? ($data['id'] ?? '') : '');

        return [
            'provider' => $this->providerKey(),
            'event_type' => (string) ($payload['type'] ?? $payload['event'] ?? $payload['kind'] ?? 'unknown'),
            'event_id' => (string) $eventId,
            'changes' => [[
                'field' => $payload['type'] ?? $payload['event'] ?? $payload['kind'] ?? null,
                'object_id' => is_array($data) ? ($data['id'] ?? null) : null,
                'created_at' => $payload['publishedAt'] ?? $payload['created_at'] ?? $payload['event_time'] ?? null,
            ]],
        ];
    }
}
