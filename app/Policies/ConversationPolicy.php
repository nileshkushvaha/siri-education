<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Owner-or-permission pattern (mirrors SupportCasePolicy). "Owner" is
 * either conversation participant (SRS §17.37 "Admin access to
 * communication history must be permission-controlled").
 */
class ConversationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:Messaging');
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->isParticipant($user) || $this->hasPermission($user, 'View:Messaging');
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $conversation->isParticipant($user);
    }

    public function close(User $user, Conversation $conversation): bool
    {
        return $conversation->isParticipant($user) || $this->hasPermission($user, 'Close:Messaging');
    }

    public function report(User $user, Conversation $conversation): bool
    {
        return $conversation->isParticipant($user);
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
