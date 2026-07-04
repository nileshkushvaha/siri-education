<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Booking authorization. Participants (attendee/host) manage their own
 * bookings; staff act through explicit permissions. `super_admin`
 * bypasses via Gate::before() — never replicated here.
 *
 * Policies decide WHAT a user may do; portal routing stays in
 * PortalResolver.
 */
class BookingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:Booking');
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->isParticipant($user, $booking)
            || $this->hasPermission($user, 'View:Booking');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:Booking');
    }

    public function update(User $user, Booking $booking): bool
    {
        return $this->hasPermission($user, 'Update:Booking');
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $this->hasPermission($user, 'Delete:Booking');
    }

    public function restore(User $user, Booking $booking): bool
    {
        return $this->hasPermission($user, 'Restore:Booking');
    }

    public function forceDelete(User $user, Booking $booking): bool
    {
        return $this->hasPermission($user, 'ForceDelete:Booking');
    }

    public function confirm(User $user, Booking $booking): bool
    {
        return $this->isHost($user, $booking)
            || $this->hasPermission($user, 'Confirm:Booking');
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->isParticipant($user, $booking)
            || $this->hasPermission($user, 'Cancel:Booking');
    }

    public function reschedule(User $user, Booking $booking): bool
    {
        return $this->isParticipant($user, $booking)
            || $this->hasPermission($user, 'Reschedule:Booking');
    }

    public function complete(User $user, Booking $booking): bool
    {
        return $this->isHost($user, $booking)
            || $this->hasPermission($user, 'Complete:Booking');
    }

    public function pay(User $user, Booking $booking): bool
    {
        return $user->id === $booking->attendee_id
            || $this->hasPermission($user, 'Update:Booking');
    }

    private function isParticipant(User $user, Booking $booking): bool
    {
        return $user->id === $booking->attendee_id || $this->isHost($user, $booking);
    }

    private function isHost(User $user, Booking $booking): bool
    {
        return $user->id === $booking->host_id;
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
