<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Post;
use App\Models\User;

/**
 * Drafts and publishing.
 *
 * Reading and reporting is open to every member; anything that changes content
 * requires an editor, and deleting a post that has already reached a network
 * is an administrative action because it cannot be undone upstream.
 */
final class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return $this->sameTenant($user, $post);
    }

    public function create(User $user): bool
    {
        return $this->canEdit($user);
    }

    public function update(User $user, Post $post): bool
    {
        return $this->canEdit($user) && $this->sameTenant($user, $post);
    }

    public function delete(User $user, Post $post): bool
    {
        if (! $this->sameTenant($user, $post)) {
            return false;
        }

        if ($post->hasPublishedVariant()) {
            return $user->role() === UserRole::Owner || $user->role() === UserRole::Admin;
        }

        return $this->canEdit($user);
    }

    public function publish(User $user, Post $post): bool
    {
        return $this->canEdit($user) && $this->sameTenant($user, $post);
    }

    public function schedule(User $user, Post $post): bool
    {
        return $this->publish($user, $post);
    }

    public function duplicate(User $user, Post $post): bool
    {
        return $this->canEdit($user) && $this->sameTenant($user, $post);
    }

    public function retryVariant(User $user, Post $post): bool
    {
        return $this->canEdit($user) && $this->sameTenant($user, $post);
    }

    private function canEdit(User $user): bool
    {
        return in_array($user->role(), [UserRole::Owner, UserRole::Admin, UserRole::Manager], true);
    }

    private function sameTenant(User $user, Post $post): bool
    {
        return (int) $user->tenant_id === (int) $post->tenant_id;
    }
}
