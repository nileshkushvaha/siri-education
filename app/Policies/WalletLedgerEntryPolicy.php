<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Ledger entries are read-only from every portal — there is no
 * create/update/delete ability because WalletLedgerService is the only
 * writer. Students may view only their own entries.
 */
class WalletLedgerEntryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:WalletLedgerEntry');
    }

    public function view(User $user, WalletLedgerEntry $entry): bool
    {
        return $user->id === $entry->user_id || $this->hasPermission($user, 'View:WalletLedgerEntry');
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
