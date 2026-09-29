<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Comment;
use App\Models\User;

final class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Comment $comment): bool
    {
        return $this->sameTenant($user, $comment);
    }

    /**
     * Reading the inbox is available to analysts; acting on it is not.
     */
    public function reply(User $user, Comment $comment): bool
    {
        return $this->canAct($user) && $this->sameTenant($user, $comment);
    }

    public function markHandled(User $user, Comment $comment): bool
    {
        return $this->canAct($user) && $this->sameTenant($user, $comment);
    }

    public function sync(User $user): bool
    {
        return in_array($user->role(), [UserRole::Owner, UserRole::Admin, UserRole::Manager], true);
    }

    private function canAct(User $user): bool
    {
        return in_array($user->role(), [UserRole::Owner, UserRole::Admin, UserRole::Manager, UserRole::Analyst], true);
    }

    private function sameTenant(User $user, Comment $comment): bool
    {
        return (int) $user->tenant_id === (int) $comment->tenant_id;
    }
}
