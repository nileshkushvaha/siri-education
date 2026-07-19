<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\LoginHistory;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserSession;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Phase 24H — GAP-013/SRS-2-20/SRS-B1-12: the single authoritative write
 * path for UserProfile::student_status. Controllers and Filament actions
 * must never update student_status directly — every transition goes
 * through here, mirroring App\Services\Instructor\InstructorOnboardingService's
 * already-shipped transitionStatus()/lockForUpdate() pattern (the
 * project's own architectural precedent for a governed lifecycle).
 *
 * Transition matrix — see StudentStatus::canTransitionTo() for the
 * authoritative table and the reasoning for treating Archived as
 * terminal in this phase (the SRS never confirms archived-student
 * restoration; this is the deliberately safer, restrictive reading).
 */
final class StudentLifecycleService
{
    public const ACTIVATE_PERMISSION = 'student.lifecycle.activate';

    public const SUSPEND_PERMISSION = 'student.lifecycle.suspend';

    public const REACTIVATE_PERMISSION = 'student.lifecycle.reactivate';

    public const ARCHIVE_PERMISSION = 'student.lifecycle.archive';

    public function __construct(private readonly AuditTrailService $auditTrail) {}

    /**
     * System-triggered Registered -> Active, fired from the email
     * verification flow (both the signed-link route and the
     * auto-verify-at-registration branch use this same entry point).
     * Idempotent and silent: no-ops without error for anyone not
     * currently Registered — including an Active student (repeated
     * verification events must not create duplicate history) and a
     * Suspended/Archived student (a delayed verification event must
     * never resurrect an administratively restricted account).
     */
    public function activateFromVerification(User $student): void
    {
        DB::transaction(function () use ($student): void {
            $profile = UserProfile::query()->where('user_id', $student->id)->lockForUpdate()->first();

            if ($profile === null || $profile->student_status !== StudentStatus::Registered) {
                return;
            }

            $this->applyTransition($profile, StudentStatus::Active, null, null);

            $this->auditTrail->logSystem(
                'student',
                'student_status_changed',
                'Student account activated (email verification)',
                $student,
                [
                    'previous_status' => StudentStatus::Registered->value,
                    'new_status' => StudentStatus::Active->value,
                    'transition_source' => 'email_verification',
                ],
            );
        });
    }

    /** Admin-triggered Registered -> Active — an available lever for edge cases; ordinary students activate via email verification, not this. */
    public function activate(User $student, User $actor): UserProfile
    {
        $this->authorizeLifecycleAction($actor, self::ACTIVATE_PERMISSION);

        return $this->transitionStatus($student, $actor, StudentStatus::Active, null, 'admin_action');
    }

    /** Registered/Active -> Suspended. Reason mandatory (administrative restriction). */
    public function suspend(User $student, User $actor, string $reason): UserProfile
    {
        $this->authorizeLifecycleAction($actor, self::SUSPEND_PERMISSION);

        return $this->transitionStatus($student, $actor, StudentStatus::Suspended, $this->requireReason($reason), 'admin_action');
    }

    /** Suspended -> Active. Reason mandatory (SRS §6.22's "Account restoration" override example requires reason capture). */
    public function reactivate(User $student, User $actor, string $reason): UserProfile
    {
        $this->authorizeLifecycleAction($actor, self::REACTIVATE_PERMISSION);

        return $this->transitionStatus($student, $actor, StudentStatus::Active, $this->requireReason($reason), 'admin_action');
    }

    /** Registered/Active/Suspended -> Archived. Terminal in this phase — reason optional, mirroring InstructorOnboardingService::archive(). */
    public function archive(User $student, User $actor, ?string $reason = null): UserProfile
    {
        $this->authorizeLifecycleAction($actor, self::ARCHIVE_PERMISSION);

        return $this->transitionStatus($student, $actor, StudentStatus::Archived, $this->sanitizeReason($reason), 'admin_action');
    }

    public function canActivate(User $actor): bool
    {
        return $this->hasPermission($actor, self::ACTIVATE_PERMISSION);
    }

    public function canSuspend(User $actor): bool
    {
        return $this->hasPermission($actor, self::SUSPEND_PERMISSION);
    }

    public function canReactivate(User $actor): bool
    {
        return $this->hasPermission($actor, self::REACTIVATE_PERMISSION);
    }

    public function canArchive(User $actor): bool
    {
        return $this->hasPermission($actor, self::ARCHIVE_PERMISSION);
    }

    /**
     * True if this student's account must be blocked from ordinary
     * login entirely. A Suspended/Archived student_status does NOT
     * block login by itself when the same account also holds a genuine,
     * bookable instructor capability — suspending the student side must
     * not silently strand an approved instructor workspace (Phase 24H
     * Step 8's explicit multi-role instruction). A student-only account
     * (or one whose instructor side isn't bookable) is fully blocked,
     * matching the SRS's blanket "Suspended or archived accounts shall
     * be prevented from authenticating."
     */
    public function blocksLogin(User $student): bool
    {
        $profile = $student->profile;

        if ($profile === null || $profile->student_status === null || ! $profile->student_status->blocksAccess()) {
            return false;
        }

        return ! in_array($profile->instructor_status, InstructorStatus::bookable(), true);
    }

    /**
     * Concurrency-safe guarded transition. Re-reads the row under
     * lockForUpdate() inside the transaction and rejects (ValidationException)
     * if the current status can't reach $to per StudentStatus::canTransitionTo()
     * — so two concurrent admin actions on the same student can't both
     * succeed, a stale Filament form re-submitted after the status
     * already changed is rejected, and a same-status no-op is rejected
     * before any write (canTransitionTo() returns false for $this === $target).
     */
    private function transitionStatus(User $student, User $actor, StudentStatus $to, ?string $reason, string $source): UserProfile
    {
        return DB::transaction(function () use ($student, $actor, $to, $reason, $source): UserProfile {
            $profile = UserProfile::query()
                ->where('user_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($profile === null || $profile->student_status === null || ! $profile->student_status->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => 'Invalid student status transition.']);
            }

            $previousStatus = $profile->student_status;

            $this->applyTransition($profile, $to, $actor, $reason);

            if ($this->profileBlocksLogin($profile)) {
                $this->revokeActiveAccess($student);
            }

            $this->auditTrail->logUser($actor, 'student', 'student_status_changed', "Student account {$to->value} for {$student->name}", $student, [
                'previous_status' => $previousStatus->value,
                'new_status' => $to->value,
                'transition_source' => $source,
                ...($reason !== null ? ['reason' => $reason] : []),
            ]);

            return $profile->fresh();
        });
    }

    private function applyTransition(UserProfile $profile, StudentStatus $to, ?User $actor, ?string $reason): void
    {
        $profile->update([
            'student_status' => $to,
            'student_status_changed_at' => now(),
            'student_status_changed_by' => $actor?->id,
            'student_status_reason' => $reason,
        ]);
    }

    private function profileBlocksLogin(UserProfile $profile): bool
    {
        if ($profile->student_status === null || ! $profile->student_status->blocksAccess()) {
            return false;
        }

        return ! in_array($profile->instructor_status, InstructorStatus::bookable(), true);
    }

    /**
     * Reuses Phase 24F's session infrastructure (UserSession + Laravel's
     * own sessions table) rather than a new subsystem — the same
     * delete-both-tables pattern SessionService already uses for
     * self-service revocation, minus the "except current session"
     * exclusion (the actor here is always a different admin user, never
     * the target). Also closes any open LoginHistory rows and clears
     * remember_token, mirroring AdminSessionService's existing
     * forced-logout convention, so a remember-me cookie can't silently
     * re-establish a session (EnsureAccountIsActive/blocksLogin would
     * reject it on the very next request regardless, but this avoids
     * even the one-request round-trip).
     */
    private function revokeActiveAccess(User $student): void
    {
        $sessionIds = UserSession::forUser($student->id)->pluck('session_id')->toArray();

        if ($sessionIds !== []) {
            UserSession::whereIn('session_id', $sessionIds)->delete();
            DB::table('sessions')->whereIn('id', $sessionIds)->delete();
        }

        LoginHistory::query()
            ->where('user_id', $student->id)
            ->whereNull('logged_out_at')
            ->update(['logged_out_at' => now()]);

        if ($student->remember_token !== null) {
            $student->forceFill(['remember_token' => null])->saveQuietly();
        }
    }

    private function requireReason(string $reason): string
    {
        $clean = $this->sanitizeReason($reason);

        if ($clean === null) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return $clean;
    }

    private function sanitizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $clean = trim(strip_tags($reason));

        return $clean === '' ? null : $clean;
    }

    private function authorizeLifecycleAction(User $actor, string $permission): void
    {
        if (! $this->hasPermission($actor, $permission)) {
            throw new AuthorizationException('You are not authorized to perform this student lifecycle action.');
        }
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->can($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
