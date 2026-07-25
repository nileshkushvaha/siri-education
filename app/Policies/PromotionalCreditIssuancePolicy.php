<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromotionalCreditIssuance;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Read-only by design — an issuance is never created, edited, or
 * deleted through any authorization-checked path; PromotionalCreditService
 * is the only writer, and it is never invoked from a user-facing action.
 * The owning student may view their own; staff need ViewPromotionalCreditIssuances.
 */
class PromotionalCreditIssuancePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewPromotionalCreditIssuances');
    }

    public function view(User $user, PromotionalCreditIssuance $issuance): bool
    {
        return $user->id === $issuance->student_id || $this->hasPermission($user, 'ViewPromotionalCreditIssuances');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PromotionalCreditIssuance $issuance): bool
    {
        return false;
    }

    public function delete(User $user, PromotionalCreditIssuance $issuance): bool
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
