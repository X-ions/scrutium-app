<?php

namespace App\Services\Security;

use App\Jobs\Security\SecurityNotificationJob;
use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\SecurityNotificationPreference;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SecurityEventDetector
{
    protected DeviceFingerprint $fingerprint;

    public function __construct(DeviceFingerprint $fingerprint)
    {
        $this->fingerprint = $fingerprint;
    }

    public function detectAndRecord(User $user, Request $request, string $eventType, array $metadata = []): SecurityEvent
    {
        $components = $this->fingerprint->extractComponents($request);
        $hash = $this->fingerprint->hashComponents($components);
        $ua = $this->fingerprint->parseUserAgent($request->userAgent() ?? '');
        $ip = $request->ip();
        $geo = $this->resolveGeo($ip);

        $device = $this->resolveDevice($user, $hash, $components, $ua, $ip, $geo, $eventType === 'login');
        $trusted = $this->resolveTrust($user, $hash);
        $session = $this->resolveSession($user, $device, $request, $ua, $ip, $geo, $eventType);

        $analysis = $this->analyse($user, $device, $trusted, $ua, $geo, $eventType, $components, $hash);

        $event = SecurityEvent::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'event_type' => $analysis['event_type'],
            'event_category' => $this->categoryFor($analysis['event_type']),
            'severity' => $this->severityFor($analysis['event_type'], $analysis['risk_score']),
            'risk_score' => $analysis['risk_score'],
            'is_suspicious' => $analysis['is_suspicious'],
            'metadata' => $metadata,
            'detection_details' => $analysis,
            'ip_address' => $ip,
            'user_agent' => $request->userAgent(),
            'device_fingerprint' => $hash,
            'device_id' => $device->id,
            'session_id' => $session?->session_id,
            'location_country' => $geo['country'] ?? null,
            'location_city' => $geo['city'] ?? null,
            'location_lat' => $geo['lat'] ?? null,
            'location_lon' => $geo['lon'] ?? null,
            'occurred_at' => now(),
        ]);

        if ($this->shouldNotify($user, $event, $device, $trusted)) {
            if (in_array($event->event_type, [
                'new_device', 'new_browser', 'new_os', 'new_location',
                'impossible_travel', 'high_risk_location',
            ], true)) {
                $this->armDeviceConfirmation($user, $device, $hash, $ua, $ip, $geo);
            }

            SecurityNotificationJob::dispatch($event->id);
        }

        return $event;
    }

    protected function resolveDevice(
        User $user,
        string $hash,
        array $components,
        array $ua,
        string $ip,
        array $geo,
        bool $countsAsLogin
    ): Device {
        $device = Device::firstOrCreate(
            ['user_id' => $user->id, 'fingerprint' => $hash],
            [
                'browser' => $ua['browser'],
                'browser_version' => $ua['browser_version'],
                'os' => $ua['os'],
                'os_version' => $ua['os_version'],
                'device_type' => $ua['device_type'],
                'device_brand' => $ua['device_brand'],
                'device_model' => $ua['device_model'],
                'fingerprint_components' => $components,
                'first_ip' => $ip,
                'first_location_country' => $geo['country'] ?? null,
                'first_location_city' => $geo['city'] ?? null,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'login_count' => 0,
            ]
        );

        $device->forceFill(['last_seen_at' => now()])->save();

        // A rejected sign-in still tells us the device exists, but it is not
        // a sign-in, so it must not inflate the activity counters.
        if ($countsAsLogin) {
            $device->increment('login_count');
        }

        return $device;
    }

    protected function resolveTrust(User $user, string $hash): ?TrustedDevice
    {
        return TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $hash)
            ->whereNull('revoked_at')
            ->whereNotNull('confirmed_at')
            ->first();
    }

    protected function resolveSession(
        User $user,
        Device $device,
        Request $request,
        array $ua,
        string $ip,
        array $geo,
        string $eventType
    ): ?UserSession {
        $current = $user->sessions()->current()->first();

        if ($eventType === 'logout') {
            // The session is about to be invalidated, so close the record here
            // or the session list keeps showing a device that is no longer
            // signed in.
            $current?->revoke($user->id, 'signed_out');

            return $current;
        }

        if ($eventType !== 'login') {
            // Password changes, device revocations and the like are not
            // sign-ins. They must not mint a new session or steal "current"
            // from the one the user is actually using.
            $current?->updateActivity();

            return $current;
        }

        $user->sessions()->current()->update(['is_current' => false]);

        return UserSession::create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'session_id' => Str::random(64),
            'ip_address' => $ip,
            'user_agent' => $request->userAgent(),
            'browser' => $ua['browser'],
            'os' => $ua['os'],
            'device_type' => $ua['device_type'],
            'location_country' => $geo['country'] ?? null,
            'location_city' => $geo['city'] ?? null,
            'location_lat' => $geo['lat'] ?? null,
            'location_lon' => $geo['lon'] ?? null,
            'is_current' => true,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    protected function analyse(
        User $user,
        Device $device,
        ?TrustedDevice $trusted,
        array $ua,
        array $geo,
        string $eventType,
        array $components,
        string $hash
    ): array {
        $details = [
            'event_type' => $eventType,
            'browser' => $ua['browser'].' '.$ua['browser_version'],
            'os' => $ua['os'].' '.$ua['os_version'],
            'device_type' => $ua['device_type'],
            'signals' => [],
            'risk_score' => 'low',
            'is_suspicious' => false,
            'is_first_contact' => $device->wasRecentlyCreated,
        ];

        if ($eventType !== 'login') {
            return $details;
        }

        $known = $user->devices()
            ->whereKeyNot($device->getKey())
            ->where('is_trusted', true)
            ->get();

        if ($device->wasRecentlyCreated && $known->isEmpty()) {
            $details['signals'][] = 'first_ever_device';
        } elseif ($device->wasRecentlyCreated) {
            $details['signals'][] = 'new_device';
        }

        if ($known->isNotEmpty() && $known->where('browser', $ua['browser'])->isEmpty()) {
            $details['signals'][] = 'new_browser';
        }

        if ($known->isNotEmpty() && $known->where('os', $ua['os'])->isEmpty()) {
            $details['signals'][] = 'new_os';
        }

        if ($this->isNewLocation($user, $geo)) {
            $details['signals'][] = 'new_location';
        }

        $travel = $this->impossibleTravel($user, $geo);
        if ($travel !== null) {
            $details['signals'][] = 'impossible_travel';
            $details['impossible_travel'] = $travel;
        }

        if ($this->isHighRiskLocation($geo)) {
            $details['signals'][] = 'high_risk_location';
        }

        if ($trusted === null && $device->wasRecentlyCreated) {
            $details['signals'][] = 'untrusted_device';
        }

        $details['risk_score'] = $this->scoreRisk($details['signals']);
        $details['is_suspicious'] = in_array('impossible_travel', $details['signals'], true)
            || in_array('high_risk_location', $details['signals'], true);

        if ($details['is_first_contact'] || in_array('new_device', $details['signals'], true)) {
            $details['event_type'] = 'new_device';
        } elseif (in_array('impossible_travel', $details['signals'], true)) {
            $details['event_type'] = 'impossible_travel';
        } elseif (in_array('high_risk_location', $details['signals'], true)) {
            $details['event_type'] = 'high_risk_location';
        } elseif (in_array('new_location', $details['signals'], true)) {
            $details['event_type'] = 'new_location';
        }

        return $details;
    }

    protected function isNewLocation(User $user, array $geo): bool
    {
        if (empty($geo['country'])) {
            return false;
        }

        return ! $user->securityEvents()
            ->where('event_type', 'login')
            ->where('location_country', $geo['country'])
            ->where('occurred_at', '>=', now()->subDays(180))
            ->exists();
    }

    protected function impossibleTravel(User $user, array $geo): ?array
    {
        if (empty($geo['lat']) || empty($geo['lon'])) {
            return null;
        }

        $previous = $user->securityEvents()
            ->whereNotNull('location_lat')
            ->where('occurred_at', '>=', now()->subHours(24))
            ->latest('occurred_at')
            ->first();

        if ($previous === null) {
            return null;
        }

        $km = $this->haversine(
            (float) $previous->location_lat,
            (float) $previous->location_lon,
            (float) $geo['lat'],
            (float) $geo['lon']
        );

        $hours = max($previous->occurred_at->diffInMinutes(now()) / 60, 0.25);
        $impliedSpeed = $km / $hours;
        $threshold = (float) config('security.impossible_travel_speed_kmh', 900);

        if ($impliedSpeed < $threshold) {
            return null;
        }

        return [
            'distance_km' => round($km, 1),
            'elapsed_hours' => round($hours, 2),
            'implied_speed_kmh' => round($impliedSpeed),
            'from' => trim(($previous->location_city ?? '').', '.($previous->location_country ?? ''), ', '),
            'to' => trim(($geo['city'] ?? '').', '.($geo['country'] ?? ''), ', '),
        ];
    }

    protected function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    protected function isHighRiskLocation(array $geo): bool
    {
        $blocked = array_filter(array_map('trim', explode(',', (string) config('security.high_risk_countries', ''))));

        return ! empty($geo['country']) && in_array($geo['country'], $blocked, true);
    }

    protected function scoreRisk(array $signals): string
    {
        $weights = [
            'first_ever_device' => 30,
            'new_device' => 25,
            'new_browser' => 10,
            'new_os' => 10,
            'new_location' => 20,
            'untrusted_device' => 15,
            'impossible_travel' => 60,
            'high_risk_location' => 50,
        ];

        $score = 0;
        foreach ($signals as $signal) {
            $score += $weights[$signal] ?? 0;
        }

        return match (true) {
            $score >= 70 => 'critical',
            $score >= 45 => 'high',
            $score >= 20 => 'medium',
            default => 'low',
        };
    }

    /**
     * Decide whether this event is worth an email.
     *
     * The rule differs by family, which is what keeps mail volume honest:
     *
     *  - Sign-ins: only the very first sign-in from a device the user has
     *    never confirmed. Trusted devices and repeat sign-ins stay silent.
     *  - Failed sign-ins: only once the attempt count crosses the
     *    threshold, so a single typo never produces an email.
     *  - Account changes: these are rare and consequential, so they always
     *    notify.
     *
     * All three then pass through the per-user hourly and daily ceilings.
     */
    protected function shouldNotify(User $user, SecurityEvent $event, Device $device, ?TrustedDevice $trusted): bool
    {
        $preference = $user->securityNotificationPreference
            ?? SecurityNotificationPreference::firstOrCreate(
                ['user_id' => $user->id],
                SecurityNotificationPreference::getDefaults()
            );

        if (! $preference->email_enabled) {
            return false;
        }

        if (! $preference->shouldNotify($event->event_type)) {
            return false;
        }

        $eligible = match ($event->event_type) {
            'login_failed' => (bool) ($event->metadata['exceeds_threshold'] ?? false),
            'login' => false,
            'new_device', 'new_browser', 'new_os', 'new_location',
            'impossible_travel', 'high_risk_location' => $trusted === null && $device->wasRecentlyCreated,
            default => true,
        };

        if (! $eligible) {
            return false;
        }

        if (! $this->withinRateLimit($user, $preference, $event)) {
            return false;
        }

        // One alert per unconfirmed device, not one per sign-in attempt.
        return ! SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('device_fingerprint', $event->device_fingerprint)
            ->where('notification_sent', true)
            ->where('occurred_at', '>=', now()->subHours((int) config('security.new_device_alert_window_hours', 24)))
            ->exists();
    }

    protected function withinRateLimit(User $user, SecurityNotificationPreference $preference, SecurityEvent $event): bool
    {
        $hourCount = SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('notification_sent', true)
            ->where('occurred_at', '>=', now()->subHour())
            ->count();

        if ($hourCount >= $preference->max_emails_per_hour) {
            return false;
        }

        $dayCount = SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('notification_sent', true)
            ->where('occurred_at', '>=', now()->subDay())
            ->count();

        return $dayCount < $preference->max_emails_per_day;
    }

    protected function armDeviceConfirmation(User $user, Device $device, string $hash, array $ua, string $ip, array $geo): void
    {
        $token = bin2hex(random_bytes(32));

        TrustedDevice::updateOrCreate(
            ['user_id' => $user->id, 'fingerprint' => $hash],
            [
                'device_id' => $device->id,
                'browser' => $ua['browser'],
                'os' => $ua['os'],
                'device_type' => $ua['device_type'],
                'trusted_ip' => $ip,
                'trusted_location_country' => $geo['country'] ?? null,
                'trusted_location_city' => $geo['city'] ?? null,
                'trust_method' => 'self_service',
                'confirmation_token' => $token,
                'token_expires_at' => now()->addDays((int) config('security.device_token_days', 7)),
                'confirmed_at' => null,
                'revoked_at' => null,
            ]
        );
    }

    protected function categoryFor(string $eventType): string
    {
        return match ($eventType) {
            'login', 'logout', 'login_failed', 'new_device', 'new_location', 'new_browser', 'new_os', 'impossible_travel', 'high_risk_location' => 'authentication',
            'password_changed', 'email_changed', 'mfa_enabled', 'mfa_disabled', 'recovery_changed', 'security_settings_changed' => 'account_security',
            'api_key_created', 'api_key_revoked', 'ownership_transferred' => 'access_control',
            'account_locked', 'suspicious_activity' => 'suspicious_activity',
            default => 'device_management',
        };
    }

    protected function severityFor(string $eventType, string $riskScore): string
    {
        if (in_array($eventType, ['account_locked', 'impossible_travel', 'ownership_transferred'], true) || $riskScore === 'critical') {
            return 'critical';
        }

        if (in_array($eventType, ['password_changed', 'email_changed', 'mfa_disabled', 'recovery_changed', 'api_key_created'], true) || $riskScore === 'high') {
            return 'high';
        }

        if (in_array($eventType, ['new_device', 'new_location', 'mfa_enabled', 'api_key_revoked', 'high_risk_location', 'suspicious_activity'], true) || $riskScore === 'medium') {
            return 'medium';
        }

        return $eventType === 'login' ? 'info' : 'low';
    }

    protected function resolveGeo(string $ip): array
    {
        if (in_array($ip, [null, '', '127.0.0.1', '::1'], true)) {
            return [];
        }

        return Cache::remember("security.geo.{$ip}", now()->addDays(30), function () use ($ip) {
            $response = @file_get_contents('https://ipwho.is/'.$ip);

            if ($response === false) {
                return [];
            }

            $payload = json_decode($response, true);

            if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
                return [];
            }

            return [
                'country' => $payload['country_code'] ?? null,
                'city' => $payload['city'] ?? null,
                'lat' => $payload['latitude'] ?? null,
                'lon' => $payload['longitude'] ?? null,
            ];
        });
    }
}
