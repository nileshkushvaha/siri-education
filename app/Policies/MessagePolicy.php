<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Gate;

/**
 * A Message has no independent ownership concept of its own; access is
 * entirely a function of conversation participation (SRS §17.37).
 * Delegates to the already-established ConversationPolicy::view() rather
 * than re-deriving "is this user a participant" here, so the two never
 * drift.
 */
class MessagePolicy
{
    use HandlesAuthorization;

    public function view(User $user, Message $message): bool
    {
        return Gate::forUser($user)->allows('view', $message->conversation);
    }
}
