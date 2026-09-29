<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * Connected social accounts.
 *
 * Connecting an account grants the platform standing access to the workspace,
 * so it is restricted to administrators even though analysts may read the
 * resulting analytics.
 */
final class SocialAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SocialAccount $account): bool
    {
        return $this->sameTenant($user, $account);
    }

    public function connect(User $user): bool
    {
        return in_array($user->role(), [UserRole::Owner, UserRole::Admin], true);
    }

    public function update(User $user, SocialAccount $account): bool
    {
        return $this->connect($user) && $this->sameTenant($user, $account);
    }

    public function disconnect(User $user, SocialAccount $account): bool
    {
        return $this->connect($user) && $this->sameTenant($user, $account);
    }

    public function refresh(User $user, SocialAccount $account): bool
    {
        return $this->connect($user) && $this->sameTenant($user, $account);
    }

    public function sync(User $user, SocialAccount $account): bool
    {
        return $this->sameTenant($user, $account);
    }

    private function sameTenant(User $user, SocialAccount $account): bool
    {
        return (int) $user->tenant_id === (int) $account->tenant_id;
    }
}
