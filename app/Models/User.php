<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'job_title',
        'avatar_path',
        'last_active_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ownedCampaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'owner_id');
    }

    public function verifiedDeliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class, 'verified_by');
    }

    public function alertSubscriptions(): HasMany
    {
        return $this->hasMany(AlertSubscription::class);
    }

    public function assignedAlerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'assigned_to');
    }

    public function role(): UserRole
    {
        $role = $this->getAttribute('role');

        if ($role instanceof UserRole) {
            return $role;
        }

        return UserRole::tryFrom((string) $role) ?? UserRole::Viewer;
    }

    public function canOperate(): bool
    {
        return in_array($this->role(), [
            UserRole::Owner,
            UserRole::Admin,
            UserRole::Manager,
        ], true);
    }

    public function canManageWorkspace(): bool
    {
        return $this->role()->canManageWorkspace();
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? asset('storage/'.ltrim((string) $this->avatar_path, '/'))
            : null;
    }
}
