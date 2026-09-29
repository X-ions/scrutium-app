<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityNotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'notify_new_device',
        'notify_new_browser',
        'notify_new_os',
        'notify_new_location',
        'notify_impossible_travel',
        'notify_suspicious_activity',
        'notify_password_change',
        'notify_email_change',
        'notify_mfa_change',
        'notify_recovery_change',
        'notify_api_key_change',
        'notify_ownership_transfer',
        'notify_account_locked',
        'notify_failed_attempts',
        'notify_high_risk_location',
        'email_enabled',
        'in_app_enabled',
        'sensitivity',
        'max_emails_per_hour',
        'max_emails_per_day',
    ];

    protected $casts = [
        'notify_new_device' => 'boolean',
        'notify_new_browser' => 'boolean',
        'notify_new_os' => 'boolean',
        'notify_new_location' => 'boolean',
        'notify_impossible_travel' => 'boolean',
        'notify_suspicious_activity' => 'boolean',
        'notify_password_change' => 'boolean',
        'notify_email_change' => 'boolean',
        'notify_mfa_change' => 'boolean',
        'notify_recovery_change' => 'boolean',
        'notify_api_key_change' => 'boolean',
        'notify_ownership_transfer' => 'boolean',
        'notify_account_locked' => 'boolean',
        'notify_failed_attempts' => 'boolean',
        'notify_high_risk_location' => 'boolean',
        'email_enabled' => 'boolean',
        'in_app_enabled' => 'boolean',
        'max_emails_per_hour' => 'integer',
        'max_emails_per_day' => 'integer',
    ];

    public const SENSITIVITY_LEVELS = [
        'low' => 'Low - Only critical alerts',
        'medium' => 'Medium - Important security events',
        'high' => 'High - All security events',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shouldNotify(string $eventType): bool
    {
        $map = [
            'new_device' => 'notify_new_device',
            'new_browser' => 'notify_new_browser',
            'new_os' => 'notify_new_os',
            'new_location' => 'notify_new_location',
            'impossible_travel' => 'notify_impossible_travel',
            'suspicious_activity' => 'notify_suspicious_activity',
            'password_changed' => 'notify_password_change',
            'email_changed' => 'notify_email_change',
            'mfa_enabled' => 'notify_mfa_change',
            'mfa_disabled' => 'notify_mfa_change',
            'recovery_changed' => 'notify_recovery_change',
            'api_key_created' => 'notify_api_key_change',
            'api_key_revoked' => 'notify_api_key_change',
            'ownership_transferred' => 'notify_ownership_transfer',
            'account_locked' => 'notify_account_locked',
            'login_failed' => 'notify_failed_attempts',
            'high_risk_location' => 'notify_high_risk_location',
        ];

        $field = $map[$eventType] ?? null;

        if ($field === null) {
            return true;
        }

        // "High" subsumes the narrower settings; "low" keeps only the
        // events that require action rather than awareness.
        if ($this->sensitivity === 'high') {
            return true;
        }

        if ($this->sensitivity === 'low') {
            return in_array($field, [
                'notify_impossible_travel',
                'notify_suspicious_activity',
                'notify_account_locked',
                'notify_password_change',
                'notify_email_change',
                'notify_mfa_change',
            ], true) && (bool) $this->{$field};
        }

        return (bool) $this->{$field};
    }

    public function getSensitivityLabel(): string
    {
        return self::SENSITIVITY_LEVELS[$this->sensitivity] ?? $this->sensitivity;
    }

    public static function getDefaults(): array
    {
        return [
            'notify_new_device' => true,
            'notify_new_browser' => true,
            'notify_new_os' => true,
            'notify_new_location' => true,
            'notify_impossible_travel' => true,
            'notify_suspicious_activity' => true,
            'notify_password_change' => true,
            'notify_email_change' => true,
            'notify_mfa_change' => true,
            'notify_recovery_change' => true,
            'notify_api_key_change' => true,
            'notify_ownership_transfer' => true,
            'notify_account_locked' => true,
            'notify_failed_attempts' => true,
            'notify_high_risk_location' => true,
            'email_enabled' => true,
            'in_app_enabled' => true,
            'sensitivity' => 'medium',
            'max_emails_per_hour' => 3,
            'max_emails_per_day' => 10,
        ];
    }
}
