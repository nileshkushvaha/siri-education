<?php

declare(strict_types=1);

namespace App\Policies;

use App\Booking\Enums\RecordingStatus;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * RECORDINGS ARE ADMIN-ONLY.
 *
 * A class recording is an administrative quality-assurance, evidence
 * and dispute-handling asset — it is not student or instructor
 * content. Participation in a recorded lesson confers no right to the
 * finished recording:
 *
 *     Admin holding the recording permission   ALLOWED
 *     Student (even their own lesson)          DENIED
 *     Instructor (even their own lesson)       DENIED
 *     Any other user                           DENIED
 *     Public / link access                     DENIED
 *
 * The same rule applies whatever produced the recording (Google Meet,
 * Zoom) and wherever it is stored (Google Drive now, S3 later) —
 * authorization lives here, never at the storage backend, which is
 * why no recording object is ever given public or link-based access.
 *
 * Do not confuse the two distinct concepts this policy sits between:
 *
 *   CONSENT / NOTICE  — participants agree to be recorded, and see the
 *                       provider's in-meeting indicator. Enforced by
 *                       RecordingEligibilityResolver and the consent
 *                       snapshot. Unaffected by this policy.
 *   ACCESS            — who may open the finished file. This policy.
 *                       Administrators only.
 *
 * Gate::before still grants super_admin, as everywhere in this
 * application.
 */
class RecordingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Recording');
    }

    /**
     * Being a participant is deliberately NOT a grant. There is no
     * student or instructor branch here, and adding one would be a
     * product policy change, not a bug fix.
     */
    public function view(User $user, Recording $recording): bool
    {
        return $user->can('View:Recording');
    }

    /**
     * Stricter than view(): permission to see that a recording exists
     * is not permission to be served bytes. An expired recording keeps
     * its metadata row as audit evidence but has no object left, and a
     * failed or still-transferring one has nothing to serve.
     */
    public function download(User $user, Recording $recording): bool
    {
        return $recording->status === RecordingStatus::Available
            && $recording->isPlayable()
            && $this->view($user, $recording);
    }

    /** Operator recovery — an administrative action, never a participant one. */
    public function retry(User $user, Recording $recording): bool
    {
        return $user->can('Retry:Recording');
    }
}
