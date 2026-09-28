<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\SecurityEventNotification;
use Illuminate\Support\Facades\Log;

class SecurityNotificationService
{
    public function sendForEvent(int $eventId): bool
    {
        $event = SecurityEvent::with('user')->find($eventId);

        if ($event === null) {
            return false;
        }

        if ($event->notification_sent) {
            return false;
        }

        if (! $event->user->email_verified_at) {
            return false;
        }

        $template = $this->templateFor($event);

        try {
            $event->user->notify(new SecurityEventNotification($event, $template));

            $event->forceFill([
                'notification_sent' => true,
                'notification_id' => (string) \Illuminate\Support\Str::uuid(),
            ])->save();

            return true;
        } catch (\Throwable $e) {
            Log::error('Security notification delivery failed', [
                'event_id' => $event->id,
                'user_id' => $event->user_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function templateFor(SecurityEvent $event): string
    {
        return match ($event->event_type) {
            'new_device', 'new_browser', 'new_os', 'new_location', 'impossible_travel', 'high_risk_location' => 'emails.security.new-device-alert',
            'login_failed' => 'emails.security.failed-login-alert',
            'account_locked', 'suspicious_activity' => 'emails.security.threat-alert',
            default => 'emails.security.account-change',
        };
    }

    public function statsFor(User $user, int $days = 30): array
    {
        $events = SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->get();

        return [
            'total' => $events->count(),
            'suspicious' => $events->where('is_suspicious', true)->count(),
            'unacknowledged' => $events->whereNull('acknowledged_at')->count(),
            'notified' => $events->where('notification_sent', true)->count(),
            'trusted_devices' => $user->devices()->where('is_trusted', true)->count(),
            'untrusted_devices' => $user->devices()->where('is_trusted', false)->count(),
            'active_sessions' => $user->activeSessions()->count(),
            'by_type' => $events->groupBy('event_type')->map->count()->sortDesc(),
            'by_day' => $events
                ->groupBy(fn (SecurityEvent $e) => $e->occurred_at->toDateString())
                ->map->count(),
        ];
    }
}
