<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Enums\StudentStatus;
use App\Exceptions\Student\StudentActionNotAvailableException;
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
 * SRS-2-20/SRS-B1-12: the single authoritative write
 * path for UserProfile::student_status. Controllers and Filament actions
 * must never update student_status directly — every transition goes
 * through here, mirroring App\Services\Instructor\InstructorOnboardingService's
 * already-shipped transitionStatus()/lockForUpdate() pattern (the
 * project's own architectural precedent for a governed lifecycle).
 *
 * Transition matrix — see StudentStatus::canTransitionTo() for the
 * authoritative table and the reasoning for treating Archived as
 * terminal (the SRS never confirms archived-student
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
        $this->systemActivateFromRegistered($student, 'email_verification', 'Student account activated (email verification)');
    }

    /**
     * System-triggered Registered -> Active
     * for a legacy account the reconciliation command has already
     * determined is eligible (student role, verified email, whole-account
     * Active, no ambiguity). Shares the exact same locked, idempotent
     * transition primitive as activateFromVerification() — the only
     * difference is transition_source, so the two remain distinguishable
     * in the audit trail. Returns whether a transition actually happened
     * (false for anyone not currently Registered under the row lock —
     * e.g. a race with a real verification event, or a second run).
     */
    public function alignLegacyVerifiedStudent(User $student): bool
    {
        return $this->systemActivateFromRegistered($student, 'legacy_verified_student_alignment', 'Student account activated (legacy verified-student alignment)');
    }

    private function systemActivateFromRegistered(User $student, string $source, string $description): bool
    {
        return DB::transaction(function () use ($student, $source, $description): bool {
            $profile = UserProfile::query()->where('user_id', $student->id)->lockForUpdate()->first();

            if ($profile === null || $profile->student_status !== StudentStatus::Registered) {
                return false;
            }

            $this->applyTransition($profile, StudentStatus::Active, null, null);

            $this->auditTrail->logSystem(
                'student',
                'student_status_changed',
                $description,
                $student,
                [
                    'previous_status' => StudentStatus::Registered->value,
                    'new_status' => StudentStatus::Active->value,
                    'transition_source' => $source,
                ],
            );

            return true;
        });
    }

    /**
     * Null-state prevention. Whenever a
     * first-party path grants the 'student' role to a user whose
     * profile doesn't already carry a governed student_status (i.e. it
     * is null — this never happens through RegistrationService, which
     * always sets Registered atomically with role assignment, but CAN
     * happen through an admin granting the role to a pre-existing user
     * via Filament, a seeder, or any other internal role-assignment
     * path), this initializes student_status to Registered — never
     * Active, since assigning a role is not the same as verifying or
     * activating an account, and normal verification/activation rules
     * still apply afterward.
     *
     * Idempotent and safe to call unconditionally after any role grant:
     * no-ops (returns false, no write, no audit) if the profile is
     * missing entirely, or if student_status is already set to ANYTHING
     * (Registered/Active/Suspended/Archived) — this only ever fills a
     * genuinely null status and never overwrites an existing one.
     *
     * $actor is the admin who performed the role grant, if any (null for
     * a system/seeder-driven grant) — audited via logUser()/logSystem()
     * accordingly, same convention as every other transition here.
     */
    public function initializeStudentRoleIfNeeded(User $user, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($user, $actor): bool {
            $profile = UserProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($profile === null || $profile->student_status !== null) {
                return false;
            }

            $profile->update(['student_status' => StudentStatus::Registered]);

            $properties = [
                'previous_status' => null,
                'new_status' => StudentStatus::Registered->value,
                'transition_source' => 'role_assignment_initialization',
            ];

            if ($actor !== null) {
                $this->auditTrail->logUser($actor, 'student', 'student_status_changed', 'Student lifecycle initialized after role assignment', $user, $properties);
            } else {
                $this->auditTrail->logSystem('student', 'student_status_changed', 'Student lifecycle initialized after role assignment', $user, $properties);
            }

            return true;
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

    /** Registered/Active/Suspended -> Archived. Terminal — reason optional, mirroring InstructorOnboardingService::archive(). */
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
     * login entirely. The SRS states plainly that "Suspended or
     * archived accounts shall be prevented from authenticating until
     * their status changes," with no multi-role carve-out — a
     * Suspended/Archived student_status blocks the WHOLE account's
     * authentication, regardless of any other role/status on it. This
     * does not touch instructor_status itself, roles, or any
     * booking/lesson/earning data — only the ability to authenticate.
     */
    public function blocksLogin(User $student): bool
    {
        $profile = $student->profile;

        return $profile !== null && ($profile->student_status?->blocksAccess() ?? false);
    }

    /**
     * The single authoritative gate for ordinary student business
     * actions (booking creation, reschedule, cancellation). Requires
     * student_status to be exactly Active — Registered, Suspended,
     * Archived, a missing profile, and a null student_status are ALL
     * rejected. A null status is invalid or ambiguous legacy/incomplete
     * data, never an implicit grant of student capability. See
     * StudentLifecycleService::initializeStudentRole() and the
     * students:reconcile-lifecycle-status command for how a null/legacy
     * status gets to a real governed status instead.
     */
    public function isEligibleForStudentActions(User $student): bool
    {
        return $student->profile?->student_status === StudentStatus::Active;
    }

    /**
     * The single authoritative guard for every
     * interactive student capability (favorites, review submission and
     * reporting, learning goals, learning-plan drafts, homework
     * submission, referral participation, ...). Requires the student
     * role AND exactly one linked profile AND student_status === Active;
     * fails closed for Registered, Suspended, Archived, a null status,
     * and a missing profile — with ONE generic exception that never
     * reveals which of those it was.
     *
     * Reads the status fresh from the database (never the possibly-stale
     * loaded relation), and — when called inside an open transaction —
     * reads it under lockForUpdate() on the same profile row
     * transitionStatus() locks, so a state-changing student action
     * wrapped in a transaction serializes against a concurrent
     * suspension: whichever commits first wins, and the loser observes
     * the committed state instead of a stale pre-suspension snapshot.
     * Read-only callers outside a transaction get a plain fresh read
     * (locking would hold nothing).
     *
     * Never mutates state; takes the actor explicitly rather than
     * reading auth() so queue/console/test callers behave identically.
     */
    public function assertEligibleForStudentAction(User $student): void
    {
        if (! $student->hasRole('student')) {
            throw StudentActionNotAvailableException::make();
        }

        $query = UserProfile::query()->where('user_id', $student->id);

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        // Eloquent's value() applies the model's enum cast, so this is a
        // StudentStatus instance (or null for a missing profile/status).
        if ($query->value('student_status') !== StudentStatus::Active) {
            throw StudentActionNotAvailableException::make();
        }
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
        return $profile->student_status?->blocksAccess() ?? false;
    }

    /**
     * Reuses the existing session infrastructure (UserSession + Laravel's
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
