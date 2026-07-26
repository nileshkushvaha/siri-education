<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Booking authorization. Participants (student/instructor) manage
 * their own bookings; staff act through explicit permissions.
 * `super_admin` bypasses via Gate::before() — never replicated here.
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

    /** Soft-delete only, through BookingArchivalService; never physical deletion. */
    public function archive(User $user, Booking $booking): bool
    {
        return $this->hasPermission($user, 'Archive:Booking');
    }

    public function restore(User $user, Booking $booking): bool
    {
        return $this->hasPermission($user, 'Restore:Booking');
    }

    /**
     * Historical booking records (and every dependent lesson/attendance/
     * financial/review/feedback record) may never be physically deleted
     * through the application, by anyone, regardless of permission.
     * Bookings\PreventsHardDeletion enforces the same rule at the model
     * layer as defense in depth.
     */
    public function forceDelete(User $user, Booking $booking): bool
    {
        return false;
    }

    public function confirm(User $user, Booking $booking): bool
    {
        return $this->isInstructor($user, $booking)
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
        return $this->isInstructor($user, $booking)
            || $this->hasPermission($user, 'Complete:Booking');
    }

    public function pay(User $user, Booking $booking): bool
    {
        return $user->id === $booking->student_id
            || $this->hasPermission($user, 'Update:Booking');
    }

    public function manageMeeting(User $user, Booking $booking): bool
    {
        return $this->isInstructor($user, $booking)
            || $this->hasPermission($user, 'Manage:BookingMeeting');
    }

    private function isParticipant(User $user, Booking $booking): bool
    {
        return $user->id === $booking->student_id || $this->isInstructor($user, $booking);
    }

    private function isInstructor(User $user, Booking $booking): bool
    {
        return $user->id === $booking->instructor_id;
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
