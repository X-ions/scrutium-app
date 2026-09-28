<?php

namespace App\Notifications;

use App\Models\SecurityEvent;
use App\Models\TrustedDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
class SecurityEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private SecurityEvent $event, private string $template)
    {
        $this->onQueue('security');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $token = $this->confirmationToken();

        return (new MailMessage)
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject($this->subject())
            ->view($this->template, $this->payload($notifiable, $token));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'security_event_id' => $this->event->id,
            'event_type' => $this->event->event_type,
            'risk_score' => $this->event->risk_score,
        ];
    }

    private function confirmationToken(): ?string
    {
        $pending = TrustedDevice::query()
            ->where('user_id', $this->event->user_id)
            ->where('fingerprint', $this->event->device_fingerprint)
            ->whereNull('revoked_at')
            ->where('token_expires_at', '>', now())
            ->first();

        return $pending?->confirmation_token;
    }

    private function subject(): string
    {
        return match ($this->event->event_type) {
            'new_device' => 'New sign-in to your Scrutium account',
            'new_location' => 'Sign-in from a new location on Scrutium',
            'new_browser' => 'Sign-in from a new browser on Scrutium',
            'new_os' => 'Sign-in from a new operating system on Scrutium',
            'impossible_travel' => 'Unusual sign-in pattern on your Scrutium account',
            'high_risk_location' => 'Sign-in from a high-risk location on Scrutium',
            'login_failed' => 'Failed sign-in attempt on your Scrutium account',
            'account_locked' => 'Your Scrutium account has been locked',
            'suspicious_activity' => 'Suspicious activity on your Scrutium account',
            'password_changed' => 'Your Scrutium password was changed',
            'email_changed' => 'Your Scrutium email address was changed',
            'mfa_enabled' => 'Two-factor authentication enabled on Scrutium',
            'mfa_disabled' => 'Two-factor authentication disabled on Scrutium',
            'recovery_changed' => 'Your Scrutium recovery methods were updated',
            'api_key_created' => 'New API key created on Scrutium',
            'api_key_revoked' => 'An API key was revoked on Scrutium',
            'ownership_transferred' => 'Workspace ownership transferred on Scrutium',
            'security_settings_changed' => 'Your Scrutium security settings changed',
            'session_revoked' => 'A Scrutium session was signed out',
            'logout' => 'You signed out of Scrutium',
            default => 'Security alert for your Scrutium account',
        };
    }

    /** @return array<string, mixed> */
    private function payload(object $notifiable, ?string $token): array
    {
        $event = $this->event;
        $details = $event->detection_details ?? [];

        $rows = [
            'Time' => $event->occurred_at->timezone(config('app.timezone'))->format('j M Y, H:i T'),
            'IP address' => $event->ip_address ?: 'Unknown',
            'Browser' => $details['browser'] ?? 'Unknown',
            'Operating system' => $details['os'] ?? 'Unknown',
            'Device type' => ucfirst((string) ($details['device_type'] ?? 'unknown')),
            'Approximate location' => $this->location(),
        ];

        if ($event->session_id) {
            $rows['Session ID'] = Str::limit($event->session_id, 16, '');
        }

        $rows['Event ID'] = (string) $event->id;

        return [
            'user' => $notifiable,
            'event' => $event,
            'headline' => $this->headline(),
            'summary' => $this->summary(),
            'signals' => $this->signals($details),
            'rows' => $rows,
            'risk_score' => $event->risk_score,
            'severity' => $event->severity,
            'confirm_url' => $token
                ? URL::signedRoute('security.devices.review', ['token' => $token])
                : null,
            'security_url' => route('security.index'),
            'sessions_url' => route('security.sessions'),
            'app_url' => config('app.url'),
        ];
    }

    private function headline(): string
    {
        return match ($this->event->event_type) {
            'new_device' => 'New device signed in to your account',
            'new_location' => 'Sign-in from a location we have not seen before',
            'new_browser' => 'Sign-in from a browser we have not seen before',
            'new_os' => 'Sign-in from an operating system we have not seen before',
            'impossible_travel' => 'We detected travel that is not physically possible',
            'high_risk_location' => 'Sign-in from a location associated with elevated risk',
            'login_failed' => 'Someone could not sign in to your account',
            'account_locked' => 'Your account has been locked for your protection',
            'suspicious_activity' => 'We detected activity that needs your attention',
            default => 'Your account security settings changed',
        };
    }

    private function summary(): string
    {
        $first = (string) $this->notifiableName();

        return match ($this->event->event_type) {
            'new_device' => "We are emailing you because a device we do not recognise signed in to {$first}'s account. If this was you, confirm the device and we will stop emailing you about it.",
            'impossible_travel' => "These sign-ins are too far apart for the time between them, which can mean the account is being used from two places at once.",
            'high_risk_location' => "This sign-in came from a region that has a higher rate of account abuse. If you were not travelling, review your account now.",
            'login_failed' => "A sign-in attempt for {$first} used an incorrect password. Repeated attempts can indicate someone is guessing your credentials.",
            'account_locked' => "We locked the account after detecting activity that put it at risk. Follow the steps below to restore access safely.",
            'suspicious_activity' => "We flagged activity on {$first}'s account that does not match normal usage.",
            default => "This change was applied to {$first}'s account. We let you know so unexpected changes are easy to catch.",
        };
    }

    private function signals(array $details): array
    {
        $labels = [
            'first_ever_device' => 'This is the first device ever used on the account',
            'new_device' => 'The device has never been used on this account before',
            'new_browser' => 'The browser has not been seen on this account before',
            'new_os' => 'The operating system has not been seen on this account before',
            'new_location' => 'The location has not been seen on this account before',
            'impossible_travel' => 'The distance covered is not possible in the elapsed time',
            'high_risk_location' => 'The location has an elevated rate of account abuse',
            'untrusted_device' => 'The device has not been confirmed as trusted',
        ];

        return array_values(array_map(
            fn (string $signal): string => $labels[$signal] ?? ucfirst(str_replace('_', ' ', $signal)),
            $details['signals'] ?? []
        ));
    }

    private function location(): string
    {
        $parts = array_filter([$this->event->location_city, $this->event->location_country]);

        return $parts === [] ? 'Unknown' : implode(', ', $parts);
    }

    private function notifiableName(): string
    {
        $name = $this->event->user?->name;

        return $name !== null && $name !== '' ? $name : 'your';
    }
}
