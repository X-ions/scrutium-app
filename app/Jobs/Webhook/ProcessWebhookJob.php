<?php

declare(strict_types=1);

namespace App\Jobs\Webhook;

use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Engagement\WebhookIngestService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-applies a webhook that was already verified and recorded.
 *
 * The event id was claimed at ingest, so a job that runs twice finds the event
 * already processed and returns without touching the payload. That is what
 * makes a redelivery safe even if the first attempt was interrupted.
 */
final class ProcessWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function __construct(
        public readonly int $webhookEventId,
        public readonly ?int $tenantId = null,
    ) {}

    public function handle(WebhookIngestService $ingest): void
    {
        if ($this->tenantId !== null) {
            TenantContext::set(Tenant::query()->find($this->tenantId));
        }

        $event = WebhookEvent::query()->withoutGlobalScopes()->find($this->webhookEventId);

        if ($event === null || $event->processed) {
            return;
        }

        $result = $ingest->replay($event);

        Log::info('socialhub.webhook.replayed', [
            'webhook_event_id' => $this->webhookEventId,
            'outcome' => $result->outcome,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('socialhub.webhook.job_failed', [
            'webhook_event_id' => $this->webhookEventId,
            'exception' => $exception::class,
        ]);
    }
}
