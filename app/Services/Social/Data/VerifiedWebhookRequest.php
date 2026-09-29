<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A webhook payload after signature verification.
 *
 * The raw body is retained verbatim because HMAC verification must run over the
 * exact bytes received; it is never included in `toArray()`.
 */
final readonly class VerifiedWebhookRequest implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $provider,
        public string $rawBody,
        public array $payload = [],
        public array $headers = [],
        public ?int $receivedAt = null,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function receivedAtDateTime(): DateTimeInterface
    {
        return (new \DateTimeImmutable)->setTimestamp($this->receivedAt ?? time());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'payload' => $this->payload,
            'received_at' => $this->receivedAt ?? time(),
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
