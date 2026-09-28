<?php

namespace App\Services\Security;

use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SecurityEventDetector
{
    protected DeviceFingerprint $fingerprintService;

    public function __construct(DeviceFingerprint $fingerprintService)
    {
        $this->fingerprintService = $fingerprintService;
    }

    public function detectAndRecord(User $user, Request $request, string $eventType, array $metadata = []): SecurityEvent
    {
        $fingerprint = $this->fingerprintService->generate($request);
        $uaData = $this->fingerprintService->parseUserAgent($request->header('User-Agent', ''));
        $ip = $request->ip();
        $location = $this->getLocation($ip);
        
        $device = $this->findOrCreateDevice($user, $fingerprint, $uaData, $ip, $location);
        $trustedDevice = $this->checkTrustedDevice($user, $fingerprint);
        $session = $this->createOrUpdateSession($user, $device, $request, $uaData, $ip, $location, $eventType);
        
        $detectionDetails = $this->analyzeSecurityContext(
            $user, $device, $trustedDevice, $session, $ip, $location, $eventType
        );

        $event = $this->createSecurityEvent($user, $eventType, $metadata, $detectionDetails, [
            'ip_address' => $ip,
            'user_agent' => $request->header('User-Agent'),
            'device_fingerprint' => $fingerprint,
            'device_id' => $device->id,
            'session_id' => $session->session_id,
            'location_country' => $location['country'] ?? null,
            'location_city' => $location['city'] ?? null,
            'location_lat' => $location['lat'] ?? null,
            'location_lon' => $location['lon'] ?? null,
        ]);

        if ($event->is_suspicious || $this->shouldNotify($event)) {
            $this->queueNotification($event);
        }

        return $event;
    }

    protected function findOrCreateDevice(User $user, string $fingerprint, array $uaData, string $ip, array $location): Device
    {
        return Device::firstOrCreate(
            ['user_id' => $user->id, 'fingerprint' => $fingerprint],
            [
                'browser' => $uaData['browser'],
                'browser_version' => $uaData['browser_version'],
                'os' => $uaData['os'],
                'os_version' => $uaData['os_version'],
                'device_type' => $uaData['device_type'],
                'device_brand' => $uaData['device_brand'],
                'device_model' => $uaData['device_model'],
                'fingerprint_components' => $this->fingerprintService->extractComponents(request()),
                'first_ip' => $ip,
                'first_location_country' => $location['country'] ?? null,
                'first_location_city' => $location['city'] ?? null,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'login_count' => 1,
            ]
        )->tap(fn ($d) => $d->recordLogin($ip, $location['country'] ?? null, $location['city'] ?? null));
    }

    protected function checkTrustedDevice(User $user, string $fingerprint): ?TrustedDevice
    {
        return TrustedDevice::where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->confirmed()
            ->first();
    }

    protected function createOrUpdateSession(
        User $user,
        Device $device,
        Request $request,
        array $uaData,
        string $ip,
        array $location,
        string $eventType
    ): UserSession {
        if ($eventType === 'login') {
            $user->sessions()->current()->update(['is_current' => false]);

            $sessionId = Str::random(64);
            return UserSession::create([
                'user_id' => $user->id,
                'device_id' => $device->id,
                'session_id' => $sessionId,
                'ip_address' => $ip,
                'user_agent' => $request->header('User-Agent'),
                'browser' => $uaData['browser'],
                'os' => $uaData['os'],
                'device_type' => $uaData['device_type'],
                'location_country' => $location['country'] ?? null,
                'location_city' => $location['city'] ?? null,
                'location_lat' => $location['lat'] ?? null,
                'location_lon' => $location['lon'] ?? null,
                'is_current' => true,
                'started_at' => now(),
                'last_activity_at' => now(),
                'expires_at' => now()->addDays(30),
            ]);
        }

        $session = $user->sessions()->current()->first();
        if ($session) {
            $session->updateActivity();
        }

        return $session ?? UserSession::create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'session_id' => Str::random(64),
            'ip_address' => $ip,
            'user_agent' => $request->header('User-Agent'),
            'browser' => $uaData['browser'],
            'os' => $uaData['os'],
            'device_type' => $uaData['device_type'],
            'location_country' => $location['country'] ?? null,
            'location_city' => $location['city'] ?? null,
            'location_lat' => $location['lat'] ?? null,
            'location_lon' => $location['lon'] ?? null,
            'is_current' => true,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    protected function analyzeSecurityContext(
        User $user,
        Device $device,
        ?TrustedDevice $trustedDevice,
        UserSession $session,
        string $ip,
        array $location,
        string $eventType
    ): array {
        $details = [
            'is_new_device' => false,
            'is_new_browser' => false,
            'is_new_os' => false,
            'is_new_location' => false,
            'impossible_travel' => false,
            'suspicious_activity' => false,
            'risk_score' => 'low',
            'triggers' => [],
        ];

        if ($device->wasRecentlyCreated) {
            $details['is_new_device'] = true;
            $details['triggers'][] = 'new_device';
        }

        $previousDevices = $user->devices()->where('id', '!=', $device->id)->get();
        
        if ($previousDevices->where('browser', $device->browser)->isEmpty()) {
            $details['is_new_browser'] = true;
            $details['triggers'][] = 'new_browser';
        }

        if ($previousDevices->where('os', $device->os)->isEmpty()) {
            $details['is_new_os'] = true;
            $details['triggers'][] = 'new_os';
        }

        $previousLocations = $user->securityEvents()
            ->whereNotNull('location_country')
            ->pluck('location_country', 'location_city')
            ->unique()
            ->toArray();

        if ($location['country'] && !in_array($location['country'], array_keys($previousLocations))) {
            $details['is_new_location'] = true;
            $details['triggers'][] = 'new_location';
        }

        $impossibleTravel = $this->checkImpossibleTravel($user, $location);
        if ($impossibleTravel) {
            $details['impossible_travel'] = true;
            $details['triggers'][] = 'impossible_travel';
            $details['impossible_travel_details'] = $impossibleTravel;
        }

        $riskScore = $this->calculateRiskScore($details, $trustedDevice, $location);
        $details['risk_score'] = $riskScore;

        if ($riskScore !== 'low' || !empty($details['triggers'])) {
            $details['suspicious_activity'] = true;
        }

        return $details;
    }

    protected function checkImpossibleTravel(User $user, array $currentLocation): ?array
    {
        if (empty($currentLocation['lat']) || empty($currentLocation['lon'])) {
            return null;
        }

        $lastEvent = $user->securityEvents()
            ->whereNotNull('location_lat')
            ->whereNotNull('location_lon')
            ->where('occurred_at', '>=', now()->subHours(24))
            ->latest('occurred_at')
            ->first();

        if (!$lastEvent) {
            return null;
        }

        $distance = $this->calculateDistance(
            $lastEvent->location_lat,
            $lastEvent->location_lon,
            $currentLocation['lat'],
            $currentLocation['lon']
        );

        $hoursDiff = $lastEvent->occurred_at->diffInHours(now());
        $maxSpeed = 1000; // km/h (commercial flight speed)

        if ($hoursDiff > 0 && ($distance / $hoursDiff) > $maxSpeed) {
            return [
                'distance_km' => round($distance, 1),
                'time_hours' => $hoursDiff,
                'required_speed_kmh' => round($distance / $hoursDiff, 1),
                'previous_location' => [
                    'lat' => $lastEvent->location_lat,
                    'lon' => $lastEvent->location_lon,
                    'country' => $lastEvent->location_country,
                    'city' => $lastEvent->location_city,
                    'timestamp' => $lastEvent->occurred_at,
                ],
                'current_location' => $currentLocation,
            ];
        }

        return null;
    }

    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km
        
        $latDiff = deg2rad($lat2 - $lat1);
        $lonDiff = deg2rad($lon2 - $lon1);
        
        $a = sin($latDiff / 2) ** 2 
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDiff / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }

    protected function calculateRiskScore(array $details, ?TrustedDevice $trustedDevice, array $location): string
    {
        $score = 0;

        if ($details['is_new_device']) $score += 30;
        if ($details['is_new_browser']) $score += 10;
        if ($details['is_new_os']) $score += 10;
        if ($details['is_new_location']) $score += 20;
        if ($details['impossible_travel']) $score += 50;
        if (!$trustedDevice) $score += 25;

        // High risk locations
        $highRiskCountries = ['KP', 'IR', 'SY', 'CU', 'SD']; // Example
        if (in_array($location['country'] ?? '', $highRiskCountries)) {
            $score += 30;
        }

        if ($score >= 70) return 'critical';
        if ($score >= 50) return 'high';
        if ($score >= 25) return 'medium';
        return 'low';
    }

    protected function shouldNotify(SecurityEvent $event): bool
    {
        $preference = $event->user->securityNotificationPreference 
            ?? SecurityNotificationPreference::getDefaults();

        if (!$preference['email_enabled']) {
            return false;
        }

        $eventType = $event->event_type;
        
        if (!$preference->shouldNotify($eventType)) {
            return false;
        }

        // Rate limiting
        $recentCount = SecurityEvent::where('user_id', $event->user_id)
            ->where('notification_sent', true)
            ->where('occurred_at', '>=', now()->subHour())
            ->count();
        
        if ($recentCount >= ($preference['max_emails_per_hour'] ?? 3)) {
            return false;
        }

        $dailyCount = SecurityEvent::where('user_id', $event->user_id)
            ->where('notification_sent', true)
            ->where('occurred_at', '>=', now()->subDay())
            ->count();
        
        if ($dailyCount >= ($preference['max_emails_per_day'] ?? 10)) {
            return false;
        }

        return true;
    }

    protected function queueNotification(SecurityEvent $event): void
    {
        // Dispatch to queue job
        SecurityNotificationJob::dispatch($event);
    }

    protected function createSecurityEvent(
        User $user,
        string $eventType,
        array $metadata,
        array $detectionDetails,
        array $context
    ): SecurityEvent {
        $eventCategory = $this->getEventCategory($eventType);
        $severity = $this->getSeverity($eventType, $detectionDetails['risk_score']);
        $isSuspicious = $detectionDetails['suspicious_activity'] ?? false;

        return SecurityEvent::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'event_type' => $eventType,
            'event_category' => $eventCategory,
            'severity' => $severity,
            'metadata' => $metadata,
            'detection_details' => $detectionDetails,
            'is_suspicious' => $isSuspicious,
            'risk_score' => $detectionDetails['risk_score'] ?? 'low',
            'occurred_at' => now(),
            ...$context,
        ]);
    }

    protected function getEventCategory(string $eventType): string
    {
        return match (true) {
            in_array($eventType, ['login', 'logout', 'login_failed', 'new_device', 'new_browser', 'new_os', 'new_location', 'impossible_travel', 'high_risk_location']) => 'authentication',
            in_array($eventType, ['password_changed', 'email_changed', 'mfa_enabled', 'mfa_disabled', 'recovery_changed', 'security_settings_changed']) => 'account_security',
            in_array($eventType, ['api_key_created', 'api_key_revoked', 'ownership_transferred']) => 'access_control',
            in_array($eventType, ['suspicious_activity', 'account_locked']) => 'suspicious_activity',
            in_array($eventType, ['session_revoked', 'device_management']) => 'device_management',
            default => 'authentication',
        };
    }

    protected function getSeverity(string $eventType, string $riskScore): string
    {
        $criticalEvents = ['account_locked', 'ownership_transferred', 'impossible_travel'];
        $highEvents = ['password_changed', 'email_changed', 'mfa_disabled', 'recovery_changed', 'api_key_created'];
        $mediumEvents = ['new_device', 'new_location', 'mfa_enabled', 'api_key_revoked', 'high_risk_location', 'suspicious_activity'];

        if (in_array($eventType, $criticalEvents) || $riskScore === 'critical') {
            return 'critical';
        }
        if (in_array($eventType, $highEvents) || $riskScore === 'high') {
            return 'high';
        }
        if (in_array($eventType, $mediumEvents) || $riskScore === 'medium') {
            return 'medium';
        }
        if ($riskScore === 'low') {
            return 'low';
        }
        return 'info';
    }

    protected function getLocation(string $ip): array
    {
        // Use IP geolocation service (cached)
        $cacheKey = "geoip_{$ip}";
        
        return Cache::remember($cacheKey, 86400 * 7, function () use ($ip) {
            // In production, use a real GeoIP service like MaxMind, IPInfo, etc.
            // For now, return mock data
            return [
                'country' => 'US',
                'city' => 'San Francisco',
                'lat' => 37.7749,
                'lon' => -122.4194,
            ];
        });
    }
}