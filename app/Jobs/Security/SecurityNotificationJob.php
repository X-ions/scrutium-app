<?php

namespace App\Jobs\Security;

use App\Services\Security\SecurityNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SecurityNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 30;

    public function __construct(public int $eventId)
    {
        $this->onQueue('security');
    }

    public function handle(SecurityNotificationService $notifications): void
    {
        $notifications->sendForEvent($this->eventId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Security notification job failed', [
            'event_id' => $this->eventId,
            'error' => $exception->getMessage(),
        ]);
    }
}
