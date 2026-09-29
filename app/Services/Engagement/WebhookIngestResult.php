<?php

declare(strict_types=1);

namespace App\Services\Engagement;

/**
 * What happened to one inbound webhook delivery.
 *
 * Returned rather than thrown so the HTTP layer can answer the provider with
 * the right status without a try/catch, and so "refused" and "duplicate" stay
 * distinguishable from "processed" in a log line.
 */
final readonly class WebhookIngestResult
{
    public const OUTCOME_PROCESSED = 'processed';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_UNMATCHED = 'unmatched';

    public const OUTCOME_REFUSED = 'refused';

    public const REASON_INVALID_SIGNATURE = 'invalid_signature';

    public const REASON_STALE_TIMESTAMP = 'stale_timestamp';

    public const REASON_UNKNOWN_PROVIDER = 'unknown_provider';

    public function __construct(
        public string $outcome,
        public ?int $webhookEventId = null,
        public ?string $reason = null,
        public int $status = 200,
    ) {}

    /**
     * The status the provider's HTTP endpoint should answer with.
     */
    public function httpStatus(): int
    {
        return $this->status;
    }

    public static function processed(int $eventId): self
    {
        return new self(self::OUTCOME_PROCESSED, $eventId, null, 200);
    }

    public static function duplicate(int $eventId): self
    {
        return new self(self::OUTCOME_DUPLICATE, $eventId, null, 200);
    }

    public static function unmatched(int $eventId): self
    {
        return new self(self::OUTCOME_UNMATCHED, $eventId, null, 200);
    }

    public static function refused(string $reason, int $status): self
    {
        return new self(self::OUTCOME_REFUSED, null, $reason, $status);
    }

    /**
     * Whether anything was actually applied, as opposed to recorded.
     */
    public function wasApplied(): bool
    {
        return $this->outcome === self::OUTCOME_PROCESSED;
    }

    /**
     * A provider should be answered 200 for anything it is allowed to know
     * about: a duplicate or an unmatched event is a valid delivery, and a
     * non-2xx would only make it redeliver forever.
     */
    public function shouldRespondOk(): bool
    {
        return $this->outcome !== self::OUTCOME_REFUSED;
    }

    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'webhook_event_id' => $this->webhookEventId,
            'reason' => $this->reason,
            'http_status' => $this->status,
        ];
    }
}
