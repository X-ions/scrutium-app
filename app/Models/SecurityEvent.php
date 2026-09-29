<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'event_type',
        'event_category',
        'severity',
        'metadata',
        'ip_address',
        'user_agent',
        'device_fingerprint',
        'device_id',
        'session_id',
        'location_country',
        'location_city',
        'location_lat',
        'location_lon',
        'risk_score',
        'is_suspicious',
        'notification_sent',
        'notification_id',
        'detection_details',
        'occurred_at',
        'acknowledged_at',
        'acknowledged_by',
        'acknowledgment_action',
    ];

    protected $casts = [
        'metadata' => 'array',
        'detection_details' => 'array',
        'occurred_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'is_suspicious' => 'boolean',
        'notification_sent' => 'boolean',
        'location_lat' => 'decimal:7',
        'location_lon' => 'decimal:7',
    ];

    public const EVENT_TYPES = [
        'login' => 'Sign In',
        'logout' => 'Sign Out',
        'login_failed' => 'Failed Sign In',
        'password_changed' => 'Password Changed',
        'email_changed' => 'Email Changed',
        'mfa_enabled' => 'MFA Enabled',
        'mfa_disabled' => 'MFA Disabled',
        'recovery_changed' => 'Recovery Methods Changed',
        'api_key_created' => 'API Key Created',
        'api_key_revoked' => 'API Key Revoked',
        'ownership_transferred' => 'Workspace Ownership Transferred',
        'account_locked' => 'Account Locked',
        'suspicious_activity' => 'Suspicious Activity Detected',
        'new_device' => 'New Device Login',
        'new_browser' => 'New Browser Login',
        'new_os' => 'New OS Login',
        'new_location' => 'New Location Login',
        'impossible_travel' => 'Impossible Travel Detected',
        'high_risk_location' => 'High Risk Location Login',
        'security_settings_changed' => 'Security Settings Changed',
        'session_revoked' => 'Session Revoked',
    ];

    public const EVENT_CATEGORIES = [
        'authentication' => 'Authentication',
        'account_security' => 'Account Security',
        'device_management' => 'Device Management',
        'access_control' => 'Access Control',
        'suspicious_activity' => 'Suspicious Activity',
    ];

    public const SEVERITIES = [
        'info' => 'Info',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    public const RISK_SCORES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeSuspicious($query)
    {
        return $query->where('is_suspicious', true);
    }

    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('occurred_at', '>=', now()->subDays($days));
    }

    public function getEventTypeLabel(): string
    {
        return self::EVENT_TYPES[$this->event_type] ?? $this->event_type;
    }

    public function getEventCategoryLabel(): string
    {
        return self::EVENT_CATEGORIES[$this->event_category] ?? $this->event_category;
    }

    public function getSeverityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? $this->severity;
    }

    public function getRiskScoreLabel(): string
    {
        return self::RISK_SCORES[$this->risk_score] ?? $this->risk_score;
    }

    public function acknowledge(int $userId, string $action): void
    {
        $this->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $userId,
            'acknowledgment_action' => $action,
        ]);
    }
}
