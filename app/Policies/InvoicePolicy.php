<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Read-only by design — an invoice is never created, edited, or
 * deleted through any authorization-checked path; InvoiceService is
 * the only writer, and it is never invoked from a user-facing action.
 * Owning student may view/download their own; staff need the explicit
 * Shield permission. super_admin bypasses via Gate::before(), never
 * replicated here.
 */
class InvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:Invoice');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->id === $invoice->user_id || $this->hasPermission($user, 'View:Invoice');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
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
