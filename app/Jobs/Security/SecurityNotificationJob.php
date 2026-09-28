<?php

namespace App\Jobs\Security;

use App\Models\SecurityEvent;
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

    public $tries = 3;
    public $backoff = [60, 300, 900];
    public $timeout = 60;

    protected SecurityEvent $event;

    public function __construct(SecurityEvent $event)
    {
        $this->event = $event;
        $this->onQueue('security-notifications');
    }

    public function handle(SecurityNotificationService $notificationService): void
    {
        $event = $this->event->fresh();
        
        if (!$event) {
            Log::warning('Security event not found for notification', [
                'event_id' => $this->event->id,
            ]);
            return;
        }

        if ($event->notification_sent) {
            Log::info('Security notification already sent', [
                'event_id' => $event->id,
            ]);
            return;
        }

        try {
            $sent = $notificationService->sendNotification($event);
            
            if ($sent) {
                Log::info('Security notification sent successfully', [
                    'event_id' => $event->id,
                    'event_type' => $event->event_type,
                    'user_id' => $event->user_id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send security notification', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Security notification job failed permanently', [
            'event_id' => $this->event->id,
            'error' => $exception->getMessage(),
        ]);
    }
}