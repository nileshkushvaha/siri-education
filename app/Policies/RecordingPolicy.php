<?php

declare(strict_types=1);

namespace App\Policies;

use App\Booking\Enums\RecordingStatus;
use App\Booking\Services\RecordingPlaybackAccessResolver;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Who may reach a lesson recording (SRS §12.20 "Recording Access").
 *
 *     Administrator holding View:Recording      view · watch · download
 *     Administrator holding Withhold:Recording  withhold / restore student access
 *     Administrator holding Retry:Recording     retry ingestion
 *     The lesson's own STUDENT                  watch — and only while
 *                                               student playback is enabled,
 *                                               the recording is serveable,
 *                                               and no admin has withheld it
 *     The lesson's instructor                   DENIED (no SRS grant exists)
 *     Any other user                            DENIED
 *     Public / link access                      DENIED
 *
 * Two abilities serve bytes and they are deliberately different:
 *
 *   watch     in-browser, application-proxied, seekable playback — the
 *             student's ability, also open to permitted administrators
 *   download  the original file as an attachment — administrators only.
 *             Being able to watch a recording is not being able to
 *             take it away.
 *
 * The student rule lives in RecordingPlaybackAccessResolver so the
 * Gate, the booking detail and the watch page all read one rule. It
 * compares the authenticated viewer against the CANONICAL row
 * (recordings.student_id, denormalized from the booking at
 * registration) — never against an id the request supplied. Knowing a
 * recording id, booking id, lesson id, or any provider identifier
 * grants nothing here.
 *
 * Distinct concepts this policy sits between, not to be confused:
 *
 *   CONSENT / NOTICE  — participants agree to be recorded, and see the
 *                       provider's in-meeting indicator. Enforced by
 *                       RecordingEligibilityResolver and the consent
 *                       snapshot. Unaffected by this policy.
 *   ACCESS            — who may open the finished file. This policy.
 *
 * Gate::before still grants super_admin, as everywhere in this
 * application.
 */
class RecordingPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly RecordingPlaybackAccessResolver $playback,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Recording');
    }

    /** Metadata visibility — the admin resource. Never a participant right. */
    public function view(User $user, Recording $recording): bool
    {
        return $user->can('View:Recording');
    }

    /**
     * In-application playback. The student branch is the ONLY place
     * participation confers anything, and every one of its conditions
     * is re-evaluated on every request (page, stream, each Range).
     */
    public function watch(User $user, Recording $recording): bool
    {
        if ($this->view($user, $recording)) {
            return $recording->isPlayable();
        }

        return $this->playback->isWatchableBy($user, $recording);
    }

    /**
     * The original as an attachment — stricter than view(): permission
     * to see that a recording exists is not permission to be served
     * bytes. An expired recording keeps its metadata row as audit
     * evidence but has no object left, and a failed or still-
     * transferring one has nothing to serve. No student branch.
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

    /** Withhold one recording from (or restore it to) its student. */
    public function withhold(User $user, Recording $recording): bool
    {
        return $user->can('Withhold:Recording');
    }
}
