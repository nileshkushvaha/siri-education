<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BookingPayment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Read-only by design — a payment attempt record is never edited or
 * deleted from the admin panel, only viewed. Owning student may view
 * their own; staff need the explicit Shield permission. super_admin
 * bypasses via Gate::before(), never replicated here.
 */
class BookingPaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:BookingPayment');
    }

    public function view(User $user, BookingPayment $bookingPayment): bool
    {
        return $user->id === $bookingPayment->user_id || $this->hasPermission($user, 'View:BookingPayment');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BookingPayment $bookingPayment): bool
    {
        return false;
    }

    public function delete(User $user, BookingPayment $bookingPayment): bool
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
