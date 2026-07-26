<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Redirect;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RedirectPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Redirect');
    }

    public function view(User $user, Redirect $redirect): bool
    {
        return $user->can('View:Redirect');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Redirect');
    }

    public function update(User $user, Redirect $redirect): bool
    {
        return $user->can('Update:Redirect');
    }

    public function activate(User $user, Redirect $redirect): bool
    {
        return $user->can('Activate:Redirect');
    }

    public function deactivate(User $user, Redirect $redirect): bool
    {
        return $user->can('Deactivate:Redirect');
    }
}
