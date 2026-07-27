<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\Activity;
use App\Models\LoginHistory;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS-2-20/SRS-B1-12: StudentLifecycleService is the
 * single authoritative write path for UserProfile::student_status.
 * Covers the governed transition matrix, reason requirements,
 * authorization, audit evidence, session revocation, and multi-role
 * (student+instructor) login-blocking behavior.
 */
class StudentLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private StudentLifecycleService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Pre-existing, unrelated: full /admin page loads touch
        // InstructorOnboardingResource::pendingReviewQuery(), which
        // queries role('instructor') — seeded defensively even though
        // this file never hits that route, matching the established
        // cross-phase workaround.
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->service = app(StudentLifecycleService::class);

        $permissions = collect([
            StudentLifecycleService::ACTIVATE_PERMISSION,
            StudentLifecycleService::SUSPEND_PERMISSION,
            StudentLifecycleService::REACTIVATE_PERMISSION,
            StudentLifecycleService::ARCHIVE_PERMISSION,
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole($managerRole);
        $this->admin->givePermissionTo($permissions);
    }

    private function studentWith(StudentStatus $status): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => $status]);

        return $student;
    }

    private function latestStudentAudit(): ?Activity
    {
        return Activity::query()
            ->where('log_name', 'student')
            ->where('event', 'student_status_changed')
            ->latest('id')
            ->first();
    }

    // ── 4/5. Suspend requires a reason ───────────────────────────────────────

    public function test_suspend_requires_a_non_blank_reason(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $this->expectException(ValidationException::class);
        $this->service->suspend($student, $this->admin, '   ');
    }

    public function test_authorized_admin_can_suspend_an_active_student(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $profile = $this->service->suspend($student, $this->admin, 'Policy violation reported.');

        $this->assertSame(StudentStatus::Suspended, $profile->student_status);
        $this->assertSame('Policy violation reported.', $profile->student_status_reason);
        $this->assertSame($this->admin->id, $profile->student_status_changed_by);
    }

    // ── 24. Every successful transition creates one correct audit entry ─────

    public function test_suspension_creates_one_correct_audit_record(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $this->service->suspend($student, $this->admin, 'Fraud review.');

        $activity = $this->latestStudentAudit();
        $this->assertNotNull($activity);
        $this->assertSame($this->admin->id, $activity->causer_id);
        $this->assertSame((string) $student->id, (string) $activity->subject_id);
        $this->assertSame('active', $activity->properties['previous_status']);
        $this->assertSame('suspended', $activity->properties['new_status']);
        $this->assertSame('Fraud review.', $activity->properties['reason']);
        $this->assertSame('admin_action', $activity->properties['transition_source']);

        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'student')->where('event', 'student_status_changed')->count(),
        );
    }

    // ── 25. Failed transition creates no misleading success audit ───────────

    public function test_a_failed_transition_creates_no_audit_record(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        try {
            $this->service->suspend($student, $this->admin, '');
        } catch (ValidationException) {
            // expected
        }

        $this->assertNull($this->latestStudentAudit());
    }

    // ── 12/13. Reactivate a Suspended student ────────────────────────────────

    public function test_authorized_admin_can_reactivate_a_suspended_student(): void
    {
        $student = $this->studentWith(StudentStatus::Suspended);

        $profile = $this->service->reactivate($student, $this->admin, 'Appeal upheld.');

        $this->assertSame(StudentStatus::Active, $profile->student_status);
    }

    public function test_reactivate_requires_a_reason(): void
    {
        $student = $this->studentWith(StudentStatus::Suspended);

        $this->expectException(ValidationException::class);
        $this->service->reactivate($student, $this->admin, '');
    }

    // ── 15/16. Archive a student; data preservation ──────────────────────────

    public function test_authorized_admin_can_archive_a_suspended_student(): void
    {
        $student = $this->studentWith(StudentStatus::Suspended);

        $profile = $this->service->archive($student, $this->admin, 'Long-term inactivity.');

        $this->assertSame(StudentStatus::Archived, $profile->student_status);
    }

    public function test_archiving_preserves_the_user_and_profile_rows(): void
    {
        $student = $this->studentWith(StudentStatus::Active);
        $studentId = $student->id;
        $profileId = $student->profile->id;

        $this->service->archive($student, $this->admin, 'Reason.');

        $this->assertDatabaseHas('users', ['id' => $studentId]);
        $this->assertDatabaseHas('user_profiles', ['id' => $profileId, 'user_id' => $studentId]);
        $this->assertNotSoftDeleted('user_profiles', ['id' => $profileId]);
    }

    // ── 18. Archived restoration follows the exact (restrictive) SRS rule ───

    public function test_archived_to_active_is_rejected_terminal_by_design(): void
    {
        $student = $this->studentWith(StudentStatus::Archived);

        $this->expectException(ValidationException::class);
        $this->service->reactivate($student, $this->admin, 'Attempted restore.');
    }

    // ── 19. Invalid transition rejected ──────────────────────────────────────

    public function test_archived_to_suspended_is_rejected(): void
    {
        $student = $this->studentWith(StudentStatus::Archived);

        $this->expectException(ValidationException::class);
        $this->service->suspend($student, $this->admin, 'Attempted.');
    }

    // ── 20. No-op same-status transition rejected ────────────────────────────

    public function test_same_status_no_op_transition_is_rejected(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $this->expectException(ValidationException::class);
        $this->service->reactivate($student, $this->admin, 'No real change.');
    }

    // ── 21/22/23. Authorization ───────────────────────────────────────────────

    public function test_unauthorized_administrator_cannot_transition_status(): void
    {
        $unauthorizedAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student = $this->studentWith(StudentStatus::Active);

        $this->expectException(AuthorizationException::class);
        $this->service->suspend($student, $unauthorizedAdmin, 'Reason.');
    }

    public function test_student_cannot_transition_their_own_status(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $this->expectException(AuthorizationException::class);
        $this->service->suspend($student, $student, 'Self-suspend attempt.');
    }

    public function test_instructor_cannot_transition_student_status(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $student = $this->studentWith(StudentStatus::Active);

        $this->expectException(AuthorizationException::class);
        $this->service->suspend($student, $instructor, 'Reason.');
    }

    // ── 7/14. Existing session revoked on a login-blocking transition ───────

    public function test_suspension_revokes_the_students_active_session(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        DB::table('sessions')->insert([
            'id' => 'test-session-id',
            'user_id' => $student->id,
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);
        UserSession::create([
            'session_id' => 'test-session-id',
            'user_id' => $student->id,
            'ip_address' => '127.0.0.1',
            'last_activity_at' => now(),
            'created_at' => now(),
        ]);
        $student->forceFill(['remember_token' => 'some-remember-token'])->saveQuietly();
        LoginHistory::create([
            'user_id' => $student->id,
            'ip_address' => '127.0.0.1',
            'successful' => true,
            'logged_in_at' => now(),
        ]);

        $this->service->suspend($student, $this->admin, 'Reason.');

        $this->assertDatabaseMissing('sessions', ['id' => 'test-session-id']);
        $this->assertDatabaseMissing('user_sessions', ['session_id' => 'test-session-id']);
        $this->assertNull($student->fresh()->remember_token);
        $this->assertNotNull(LoginHistory::query()->where('user_id', $student->id)->first()->logged_out_at);
    }

    // ── 24/26. Multi-role behavior: account-wide, no bypass ──────────────────

    /** A bookable instructor capability never bypasses the SRS's blanket authentication restriction. */
    public function test_blocks_login_is_true_even_when_the_same_account_has_a_bookable_instructor_status(): void
    {
        $student = $this->studentWith(StudentStatus::Suspended);
        $student->assignRole('instructor');
        $student->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->assertTrue($this->service->blocksLogin($student->fresh()));
    }

    public function test_blocks_login_is_true_for_a_student_only_suspended_account(): void
    {
        $student = $this->studentWith(StudentStatus::Suspended);

        $this->assertTrue($this->service->blocksLogin($student->fresh()));
    }

    public function test_blocks_login_is_true_when_instructor_status_is_not_bookable(): void
    {
        $student = $this->studentWith(StudentStatus::Archived);
        $student->assignRole('instructor');
        $student->profile()->update(['instructor_status' => InstructorStatus::Suspended]);

        $this->assertTrue($this->service->blocksLogin($student->fresh()));
    }

    public function test_blocks_login_is_false_for_an_active_student(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $this->assertFalse($this->service->blocksLogin($student->fresh()));
    }

    // ── Strict isEligibleForStudentActions() ──────────────────────────────────

    public function test_active_student_is_eligible_for_student_actions(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $this->assertTrue($this->service->isEligibleForStudentActions($student->fresh()));
    }

    public function test_registered_student_is_not_eligible(): void
    {
        $student = $this->studentWith(StudentStatus::Registered);

        $this->assertFalse($this->service->isEligibleForStudentActions($student->fresh()));
    }

    public function test_suspended_student_is_not_eligible(): void
    {
        $student = $this->studentWith(StudentStatus::Suspended);

        $this->assertFalse($this->service->isEligibleForStudentActions($student->fresh()));
    }

    public function test_archived_student_is_not_eligible(): void
    {
        $student = $this->studentWith(StudentStatus::Archived);

        $this->assertFalse($this->service->isEligibleForStudentActions($student->fresh()));
    }

    /** Null is invalid/ambiguous data, never an implicit Active grant. */
    public function test_null_status_student_is_not_eligible(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->assertNull($student->fresh()->profile->student_status);
        $this->assertFalse($this->service->isEligibleForStudentActions($student->fresh()));
    }

    public function test_missing_profile_student_is_not_eligible(): void
    {
        $student = $this->studentWith(StudentStatus::Active);
        $student->profile()->delete();

        $this->assertFalse($this->service->isEligibleForStudentActions($student->fresh()));
    }

    /** Direct service invocation cannot bypass strict status enforcement — there is no alternate/weaker entry point. */
    public function test_direct_service_invocation_cannot_bypass_strict_status_enforcement(): void
    {
        $registered = $this->studentWith(StudentStatus::Registered);
        $nullStatus = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $nullStatus->assignRole('student');

        $this->assertFalse(app(StudentLifecycleService::class)->isEligibleForStudentActions($registered->fresh()));
        $this->assertFalse(app(StudentLifecycleService::class)->isEligibleForStudentActions($nullStatus->fresh()));
    }

    // ── Null-state prevention on role assignment ──────────────────────────────

    public function test_initializing_a_null_status_student_role_sets_registered(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $applied = $this->service->initializeStudentRoleIfNeeded($student, $this->admin);

        $this->assertTrue($applied);
        $this->assertSame(StudentStatus::Registered, $student->fresh()->profile->student_status);
    }

    public function test_initialization_does_not_overwrite_active(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $applied = $this->service->initializeStudentRoleIfNeeded($student, $this->admin);

        $this->assertFalse($applied);
        $this->assertSame(StudentStatus::Active, $student->fresh()->profile->student_status);
    }

    public function test_initialization_does_not_overwrite_suspended(): void
    {
        $student = $this->studentWith(StudentStatus::Suspended);

        $applied = $this->service->initializeStudentRoleIfNeeded($student, $this->admin);

        $this->assertFalse($applied);
        $this->assertSame(StudentStatus::Suspended, $student->fresh()->profile->student_status);
    }

    public function test_initialization_does_not_overwrite_archived(): void
    {
        $student = $this->studentWith(StudentStatus::Archived);

        $applied = $this->service->initializeStudentRoleIfNeeded($student, $this->admin);

        $this->assertFalse($applied);
        $this->assertSame(StudentStatus::Archived, $student->fresh()->profile->student_status);
    }

    public function test_initialization_produces_audit_evidence(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->service->initializeStudentRoleIfNeeded($student, $this->admin);

        $activity = Activity::query()
            ->where('log_name', 'student')
            ->where('event', 'student_status_changed')
            ->where('subject_id', $student->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($this->admin->id, $activity->causer_id);
        $this->assertNull($activity->properties['previous_status']);
        $this->assertSame('registered', $activity->properties['new_status']);
        $this->assertSame('role_assignment_initialization', $activity->properties['transition_source']);
    }

    /** A missing profile means there's nothing to initialize — no crash, no false-positive audit. */
    public function test_initialization_is_a_no_op_when_profile_is_missing(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->delete();

        $applied = $this->service->initializeStudentRoleIfNeeded($student, $this->admin);

        $this->assertFalse($applied);
    }
}
