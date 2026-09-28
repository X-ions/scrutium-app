<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityNotificationPreference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecurityNotificationService
{
    public function sendNotification(SecurityEvent $event): bool
    {
        $preference = $event->user->securityNotificationPreference 
            ?? SecurityNotificationPreference::getDefaults();

        if (!$preference['email_enabled'] && !$preference['in_app_enabled']) {
            return false;
        }

        if ($this->isDuplicateNotification($event)) {
            return false;
        }

        $groupedEvents = $this->getGroupedEvents($event);
        
        if ($preference['email_enabled']) {
            $this->sendEmail($event, $groupedEvents);
        }

        if ($preference['in_app_enabled']) {
            $this->createInAppNotification($event, $groupedEvents);
        }

        $event->update([
            'notification_sent' => true,
            'notification_id' => Str::uuid(),
        ]);

        return true;
    }

    protected function isDuplicateNotification(SecurityEvent $event): bool
    {
        $cacheKey = "security_notif_{$event->user_id}_{$event->event_type}_{$event->device_fingerprint}";
        
        if (Cache::has($cacheKey)) {
            return true;
        }

        Cache::put($cacheKey, true, now()->addMinutes(30));
        return false;
    }

    protected function getGroupedEvents(SecurityEvent $event): array
    {
        $window = now()->subMinutes(15);
        
        return SecurityEvent::where('user_id', $event->user_id)
            ->where('event_type', $event->event_type)
            ->where('occurred_at', '>=', $window)
            ->where('id', '!=', $event->id)
            ->where('notification_sent', false)
            ->get()
            ->toArray();
    }

    protected function sendEmail(SecurityEvent $event, array $groupedEvents): void
    {
        $template = $this->getEmailTemplate($event->event_type);
        
        if (!$template) {
            Log::warning('No email template for security event', ['event_type' => $event->event_type]);
            return;
        }

        try {
            $event->user->notify(new \App\Notifications\SecurityEventNotification($event, $groupedEvents, $template));
        } catch (\Exception $e) {
            Log::error('Failed to send security notification email', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function createInAppNotification(SecurityEvent $event, array $groupedEvents): void
    {
        // In-app notification logic would go here
        // Could use a notifications table or Laravel's built-in notifications
    }

    protected function getEmailTemplate(string $eventType): ?string
    {
        return match ($eventType) {
            'new_device', 'new_browser', 'new_os', 'new_location', 'impossible_travel', 'high_risk_location' => 'emails.security.new-device-alert',
            'login_failed' => 'emails.security.failed-login-alert',
            'password_changed' => 'emails.security.password-changed',
            'email_changed' => 'emails.security.email-changed',
            'mfa_enabled', 'mfa_disabled' => 'emails.security.mfa-changed',
            'recovery_changed' => 'emails.security.recovery-changed',
            'api_key_created', 'api_key_revoked' => 'emails.security.api-key-changed',
            'ownership_transferred' => 'emails.security.ownership-transferred',
            'account_locked' => 'emails.security.account-locked',
            'suspicious_activity' => 'emails.security.suspicious-activity',
            'session_revoked' => 'emails.security.session-revoked',
            default => 'emails.security.generic-alert',
        };
    }

    public function sendBulkDigest(User $user, Carbon $since): void
    {
        $events = SecurityEvent::where('user_id', $user->id)
            ->where('occurred_at', '>=', $since)
            ->where('notification_sent', false)
            ->where('is_suspicious', true)
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $preference = $user->securityNotificationPreference 
            ?? SecurityNotificationPreference::getDefaults();

        if ($preference['email_enabled']) {
            $user->notify(new \App\Notifications\SecurityDigestNotification($events));
        }

        $events->each->update(['notification_sent' => true]);
    }

    public function getNotificationStats(User $user, int $days = 30): array
    {
        $since = now()->subDays($days);
        
        $events = SecurityEvent::where('user_id', $user->id)
            ->where('occurred_at', '>=', $since)
            ->get();

        return [
            'total_events' => $events->count(),
            'by_type' => $events->groupBy('event_type')->map->count()->toArray(),
            'by_severity' => $events->groupBy('severity')->map->count()->toArray(),
            'by_category' => $events->groupBy('event_category')->map->count()->toArray(),
            'suspicious_count' => $events->where('is_suspicious', true)->count(),
            'notifications_sent' => $events->where('notification_sent', true)->count(),
            'unacknowledged' => $events->whereNull('acknowledged_at')->count(),
        ];
    }
}