<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Students may view (never manage) their own wallet only. Every
 * mutating ability (freeze/unfreeze/close/admin-adjustment/reversal)
 * is gated behind the single `manage` ability — an admin-only Shield
 * permission, never ownership-based. `super_admin` bypasses via
 * Gate::before(), never replicated here.
 */
class WalletPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:Wallet');
    }

    public function view(User $user, Wallet $wallet): bool
    {
        return $user->id === $wallet->user_id || $this->hasPermission($user, 'View:Wallet');
    }

    /** Not exposed in the admin panel — wallets are only ever created via WalletService::getOrCreateWallet(). */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:Wallet');
    }

    /** No admin form ever edits a wallet directly; kept for policy completeness only. */
    public function update(User $user, Wallet $wallet): bool
    {
        return $this->hasPermission($user, 'Manage:Wallet');
    }

    /** A wallet is never deleted — `close()` is the terminal state. */
    public function delete(User $user, Wallet $wallet): bool
    {
        return false;
    }

    public function manage(User $user, ?Wallet $wallet = null): bool
    {
        return $this->hasPermission($user, 'Manage:Wallet');
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
