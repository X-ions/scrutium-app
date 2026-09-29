<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\SecurityNotificationPreference;
use App\Models\TrustedDevice;
use App\Models\UserSession;
use App\Services\Security\SecurityEventDetector;
use App\Services\Security\SecurityNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function __construct(
        private readonly SecurityEventDetector $detector,
        private readonly SecurityNotificationService $notifications,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $events = SecurityEvent::query()
            ->where('user_id', $user->id)
            ->latest('occurred_at')
            ->paginate(25);

        return view('pages/security/index', [
            'title' => 'Security',
            'events' => $events,
            'stats' => $this->notifications->statsFor($user),
            'trustedDevices' => $user->devices()->where('is_trusted', true)->latest('last_seen_at')->get(),
            'pendingDevices' => TrustedDevice::query()
                ->where('user_id', $user->id)
                ->pending()
                ->latest('created_at')
                ->get(),
            'preferences' => $user->securityNotificationPreference
                ?? SecurityNotificationPreference::create(
                    ['user_id' => $user->id] + SecurityNotificationPreference::getDefaults()
                ),
        ]);
    }

    public function devices(Request $request): View
    {
        $user = $request->user();

        return view('pages/security/devices', [
            'title' => 'Devices',
            'trustedDevices' => $user->devices()->where('is_trusted', true)->latest('last_seen_at')->get(),
            'untrustedDevices' => $user->devices()->where('is_trusted', false)->latest('last_seen_at')->get(),
            'blockedDevices' => $user->devices()->where('is_blocked', true)->latest('blocked_at')->get(),
        ]);
    }

    /**
     * Landing page for the emailed "This was me" link.
     *
     * Deliberately read-only. Mail clients and link scanners routinely
     * pre-fetch URLs, so a GET that confirmed the device would let a
     * scanner mark an unknown device as trusted before the user ever
     * saw the email. The mutation happens on the POST below.
     */
    public function reviewDeviceConfirmation(Request $request, string $token): View|RedirectResponse
    {
        $trusted = TrustedDevice::query()
            ->where('user_id', $request->user()->id)
            ->where('confirmation_token', $token)
            ->pending()
            ->first();

        if ($trusted === null) {
            return redirect()
                ->route('security.devices')
                ->withErrors(['device' => 'That confirmation link is invalid or has expired.']);
        }

        return view('pages/security/confirm-device', [
            'title' => 'Confirm this device',
            'trusted' => $trusted,
        ]);
    }

    public function confirmDevice(Request $request, string $token): RedirectResponse
    {
        $trusted = TrustedDevice::query()
            ->where('user_id', $request->user()->id)
            ->where('confirmation_token', $token)
            ->pending()
            ->first();

        if ($trusted === null) {
            return redirect()
                ->route('security.devices')
                ->withErrors(['device' => 'That confirmation link is invalid or has expired.']);
        }

        $trusted->confirm($request->user()->id);

        SecurityEvent::query()
            ->where('user_id', $request->user()->id)
            ->where('device_fingerprint', $trusted->fingerprint)
            ->whereNull('acknowledged_at')
            ->get()
            ->each->acknowledge($request->user()->id, 'device_confirmed');

        return redirect()
            ->route('security.index')
            ->with('success', 'Device confirmed. We will not email you about this device again.');
    }

    public function trustDevice(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        abort_if($device->is_blocked, 404);

        $device->markTrusted($request->user()->id, 'user_confirmed');

        SecurityEvent::query()
            ->where('user_id', $request->user()->id)
            ->where('device_fingerprint', $device->fingerprint)
            ->whereNull('acknowledged_at')
            ->get()
            ->each->acknowledge($request->user()->id, 'device_confirmed');

        return back()->with('success', 'Device marked as trusted.');
    }

    public function revokeDevice(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        $device->revokeTrust($request->user()->id, 'user_revoked');

        return back()->with('success', 'Device removed from your trusted list. The next sign-in from it will require confirmation.');
    }

    public function blockDevice(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        $device->block($request->user()->id, 'user_blocked');

        UserSession::query()
            ->where('user_id', $request->user()->id)
            ->where('device_id', $device->id)
            ->active()
            ->get()
            ->each->revoke($request->user()->id, 'device_blocked');

        $this->detector->detectAndRecord($request->user(), $request, 'session_revoked', [
            'device' => $device->getDisplayName(),
        ]);

        return back()->with('success', 'Device blocked and its sessions ended.');
    }

    public function sessions(Request $request): View
    {
        $user = $request->user();
        $current = $request->session()->getId();

        $sessions = UserSession::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderByDesc('last_activity_at')
            ->get();

        return view('pages/security/sessions', [
            'title' => 'Active sessions',
            'sessions' => $sessions,
            'currentSessionId' => $current,
        ]);
    }

    public function revokeSession(Request $request, UserSession $session): RedirectResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        if ($session->session_id === $request->session()->getId()) {
            return back()->withErrors(['session' => 'You cannot end the session you are currently using.']);
        }

        $session->revoke($request->user()->id, 'user_revoked');

        return back()->with('success', 'Session ended.');
    }

    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $current = $request->session()->getId();

        UserSession::query()
            ->where('user_id', $request->user()->id)
            ->active()
            ->where('session_id', '!=', $current)
            ->get()
            ->each->revoke($request->user()->id, 'user_revoked_all');

        $this->detector->detectAndRecord($request->user(), $request, 'session_revoked', [
            'scope' => 'all_other_sessions',
        ]);

        return back()->with('success', 'All other sessions have been ended.');
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sensitivity' => ['required', 'in:low,medium,high'],
            'email_enabled' => ['nullable', 'boolean'],
            'in_app_enabled' => ['nullable', 'boolean'],
            'max_emails_per_hour' => ['required', 'integer', 'min:1', 'max:10'],
            'max_emails_per_day' => ['required', 'integer', 'min:1', 'max:50'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string'],
        ]);

        $toggles = [
            'notify_new_device', 'notify_new_browser', 'notify_new_os', 'notify_new_location',
            'notify_impossible_travel', 'notify_suspicious_activity', 'notify_password_change',
            'notify_email_change', 'notify_mfa_change', 'notify_recovery_change', 'notify_api_key_change',
            'notify_ownership_transfer', 'notify_account_locked', 'notify_failed_attempts',
            'notify_high_risk_location',
        ];

        $enabled = $request->input('events', []);

        $preference = SecurityNotificationPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['user_id' => $request->user()->id] + SecurityNotificationPreference::getDefaults()
        );

        $attributes = [
            'sensitivity' => $validated['sensitivity'],
            'email_enabled' => $request->boolean('email_enabled'),
            'in_app_enabled' => $request->boolean('in_app_enabled'),
            'max_emails_per_hour' => $validated['max_emails_per_hour'],
            'max_emails_per_day' => $validated['max_emails_per_day'],
        ];

        foreach ($toggles as $toggle) {
            $attributes[$toggle] = in_array($toggle, $enabled, true);
        }

        $preference->update($attributes);

        return back()->with('success', 'Security notification preferences saved.');
    }

    public function acknowledge(Request $request, SecurityEvent $event): RedirectResponse
    {
        abort_unless($event->user_id === $request->user()->id, 404);

        if ($event->acknowledged_at === null) {
            $event->acknowledge($request->user()->id, 'reviewed');
        }

        return back();
    }
}
