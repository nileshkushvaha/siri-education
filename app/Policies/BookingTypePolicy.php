<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BookingType;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class BookingTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:BookingType');
    }

    public function view(User $user, BookingType $bookingType): bool
    {
        return $this->hasPermission($user, 'View:BookingType');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:BookingType');
    }

    public function update(User $user, BookingType $bookingType): bool
    {
        return $this->hasPermission($user, 'Update:BookingType');
    }

    public function delete(User $user, BookingType $bookingType): bool
    {
        return $this->hasPermission($user, 'Delete:BookingType');
    }

    public function restore(User $user, BookingType $bookingType): bool
    {
        return $this->hasPermission($user, 'Restore:BookingType');
    }

    public function forceDelete(User $user, BookingType $bookingType): bool
    {
        return $this->hasPermission($user, 'ForceDelete:BookingType');
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
