<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\User;

final class MediaAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MediaAsset $asset): bool
    {
        return $this->sameTenant($user, $asset);
    }

    public function upload(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, MediaAsset $asset): bool
    {
        return $this->canManage($user) && $this->sameTenant($user, $asset);
    }

    public function delete(User $user, MediaAsset $asset): bool
    {
        return $this->canManage($user) && $this->sameTenant($user, $asset);
    }

    private function canManage(User $user): bool
    {
        return in_array($user->role(), [UserRole::Owner, UserRole::Admin, UserRole::Manager, UserRole::Analyst], true);
    }

    private function sameTenant(User $user, MediaAsset $asset): bool
    {
        return (int) $user->tenant_id === (int) $asset->tenant_id;
    }
}
