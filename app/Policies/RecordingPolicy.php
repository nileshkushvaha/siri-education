<?php

declare(strict_types=1);

namespace App\Policies;

use App\Booking\Enums\RecordingStatus;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * SIRI — not the storage backend — decides who may reach a recording.
 * Google Drive files are private to the platform account and no
 * "anyone with the link" permission is ever set, so this policy is the
 * only door, and it is re-evaluated on every single request rather
 * than baked into a URL that could be forwarded.
 *
 * Access derives from the lesson relationship the SRS already defines
 * (§12.20: student, instructor, administrator): the recording's own
 * student and its own instructor, plus an admin explicitly holding the
 * permission. Instructor access is scoped to lessons they actually
 * delivered — there is no blanket "instructors can view recordings"
 * right, because the SRS grants none. Gate::before still grants
 * super_admin regardless of this policy.
 */
class RecordingPolicy
{
    use HandlesAuthorization;

    /** Admin list access only — a student/instructor never browses "all recordings", only their own. */
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Recording');
    }

    public function view(User $user, Recording $recording): bool
    {
        if ($this->isParticipant($user, $recording)) {
            return true;
        }

        return $user->can('View:Recording');
    }

    /**
     * Being allowed to SEE a recording exists is not being allowed to
     * be served its content: an Expired recording keeps its metadata
     * row for audit purposes but has no object left, and a Failed or
     * still-transferring one has nothing to serve either.
     */
    public function download(User $user, Recording $recording): bool
    {
        return $recording->status === RecordingStatus::Available
            && $recording->isPlayable()
            && $this->view($user, $recording);
    }

    /**
     * Operator recovery is an administrative action, never a
     * participant one — a student must not be able to drive load
     * against a meeting provider or a storage backend.
     */
    public function retry(User $user, Recording $recording): bool
    {
        return $user->can('Retry:Recording');
    }

    private function isParticipant(User $user, Recording $recording): bool
    {
        return $user->id === $recording->student_id || $user->id === $recording->teacher_id;
    }
}
