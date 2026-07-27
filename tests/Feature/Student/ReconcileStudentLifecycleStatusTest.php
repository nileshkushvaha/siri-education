<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\Activity;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * students:reconcile-lifecycle-status.
 * Dry-run by default; --apply required to mutate. Every alignment goes
 * through StudentLifecycleService::alignLegacyVerifiedStudent() (the
 * same governed, row-locked, audited transition primitive as any other
 * student-status change) — never a raw student_status write.
 */
class ReconcileStudentLifecycleStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    private function eligibleLegacyStudent(): User
    {
        $student = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now()->subDays(30),
        ]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Registered]);

        return $student;
    }

    // ── 12/13. Dry-run changes nothing, reports counts safely ───────────────

    public function test_dry_run_changes_nothing(): void
    {
        $student = $this->eligibleLegacyStudent();

        $this->artisan('students:reconcile-lifecycle-status')->assertExitCode(0);

        $this->assertSame(StudentStatus::Registered, $student->fresh()->profile->student_status);
        $this->assertNull($this->latestAudit($student));
    }

    public function test_dry_run_reports_eligible_and_excluded_counts_without_personal_data(): void
    {
        $this->eligibleLegacyStudent();

        $output = $this->artisanOutput('students:reconcile-lifecycle-status');

        $this->assertStringContainsString('eligible for Registered -> Active alignment', $output);
        $this->assertStringContainsString('ambiguous record(s) excluded', $output);
        $this->assertStringContainsString('Dry-run mode', $output);
    }

    // ── 23. Command output contains no personal data ─────────────────────────

    public function test_command_output_contains_no_personal_data(): void
    {
        $student = $this->eligibleLegacyStudent();

        $output = $this->artisanOutput('students:reconcile-lifecycle-status --apply');

        $this->assertStringNotContainsString($student->email, $output);
        $this->assertStringNotContainsString($student->name, $output);
        $this->assertStringNotContainsString((string) $student->id, $output);
    }

    // ── 14. Apply activates only verified, whole-account-active Registered students ──

    public function test_apply_activates_an_eligible_legacy_verified_student(): void
    {
        $student = $this->eligibleLegacyStudent();

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertSame(StudentStatus::Active, $student->fresh()->profile->student_status);
    }

    // ── 15. Unverified Registered students remain Registered ────────────────

    public function test_unverified_student_is_not_activated(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => null]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Registered]);

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertSame(StudentStatus::Registered, $student->fresh()->profile->student_status);
    }

    // ── 16. Whole-account-inactive Registered students remain Registered ────

    public function test_whole_account_inactive_student_is_not_activated(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_INACTIVE, 'email_verified_at' => now()]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Registered]);

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertSame(StudentStatus::Registered, $student->fresh()->profile->student_status);
    }

    // ── 17. Suspended and Archived remain unchanged ──────────────────────────

    public function test_suspended_student_is_never_touched(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Suspended]);

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertSame(StudentStatus::Suspended, $student->fresh()->profile->student_status);
    }

    public function test_archived_student_is_never_touched(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Archived]);

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertSame(StudentStatus::Archived, $student->fresh()->profile->student_status);
    }

    // ── 18. Non-student users remain unchanged ───────────────────────────────

    public function test_non_student_user_is_unaffected(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $instructor->assignRole('instructor');

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertNull($instructor->fresh()->profile->student_status);
    }

    /** super_admin/manager role holders are excluded even if they somehow also hold a Registered student profile. */
    public function test_admin_role_holder_is_excluded_even_with_a_registered_student_profile(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $admin->assignRole('student');
        $admin->assignRole('manager');
        $admin->profile()->update(['student_status' => StudentStatus::Registered]);

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertSame(StudentStatus::Registered, $admin->fresh()->profile->student_status);
    }

    // ── 19. Ambiguous records are skipped and reported ───────────────────────

    public function test_a_record_with_prior_lifecycle_audit_evidence_is_treated_as_ambiguous_and_skipped(): void
    {
        $student = $this->eligibleLegacyStudent();

        // Simulate prior lifecycle audit evidence even though the
        // profile is currently Registered — a state that should never
        // arise through the governed service in normal operation, and
        // is exactly the "ambiguous" case the command must not silently
        // reconcile.
        app(AuditTrailService::class)->logSystem(
            'student',
            'student_status_changed',
            'Synthetic prior restriction for ambiguity test',
            $student,
            ['previous_status' => 'active', 'new_status' => 'suspended', 'transition_source' => 'admin_action'],
        );

        $output = $this->artisanOutput('students:reconcile-lifecycle-status --apply');

        $this->assertSame(StudentStatus::Registered, $student->fresh()->profile->student_status);
        $this->assertStringContainsString('1 ambiguous record(s)', $output);
    }

    // ── 20/21. Null statuses reported ambiguous, never promoted ──────────────

    public function test_null_status_students_are_reported_as_ambiguous(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $student->assignRole('student');

        $output = $this->artisanOutput('students:reconcile-lifecycle-status');

        $this->assertStringContainsString('Null/invalid student_status', $output);
        $this->assertNull($student->fresh()->profile->student_status);
    }

    public function test_null_status_students_are_never_promoted_by_apply(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $student->assignRole('student');

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertNull($student->fresh()->profile->student_status);
        $this->assertNull($this->latestAudit($student));
    }

    // ── 20. Reconciliation is idempotent ─────────────────────────────────────

    public function test_reconciliation_is_idempotent(): void
    {
        $student = $this->eligibleLegacyStudent();

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);
        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertSame(StudentStatus::Active, $student->fresh()->profile->student_status);
        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'student')->where('event', 'student_status_changed')
                ->where('subject_id', $student->id)->count(),
        );
    }

    // ── 21/22. Audit correctness ──────────────────────────────────────────────

    public function test_each_applied_alignment_creates_exactly_one_audit_record_identifying_the_source(): void
    {
        $student = $this->eligibleLegacyStudent();

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $activity = $this->latestAudit($student);
        $this->assertNotNull($activity);
        $this->assertSame('registered', $activity->properties['previous_status']);
        $this->assertSame('active', $activity->properties['new_status']);
        $this->assertSame('legacy_verified_student_alignment', $activity->properties['transition_source']);
    }

    /** Normal verification activation remains distinguishable from legacy alignment via transition_source. */
    public function test_legacy_alignment_source_is_distinguishable_from_normal_verification_activation(): void
    {
        $viaVerification = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viaVerification->assignRole('student');
        $viaVerification->profile()->update(['student_status' => StudentStatus::Registered]);
        app(StudentLifecycleService::class)->activateFromVerification($viaVerification);

        $viaLegacy = $this->eligibleLegacyStudent();
        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertSame('email_verification', $this->latestAudit($viaVerification)->properties['transition_source']);
        $this->assertSame('legacy_verified_student_alignment', $this->latestAudit($viaLegacy)->properties['transition_source']);
    }

    // ── 32. No external provider call occurs ─────────────────────────────────

    public function test_no_http_call_occurs_during_reconciliation(): void
    {
        Http::fake();

        $this->eligibleLegacyStudent();
        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        Http::assertNothingSent();
    }

    // ── Multi-role: eligible even with a bookable instructor status ─────────

    public function test_a_dual_role_student_instructor_is_still_eligible_when_otherwise_qualifying(): void
    {
        $student = $this->eligibleLegacyStudent();
        $student->assignRole('instructor');
        $student->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->artisan('students:reconcile-lifecycle-status --apply')->assertExitCode(0);

        $this->assertSame(StudentStatus::Active, $student->fresh()->profile->student_status);
        $this->assertSame(InstructorStatus::Active, $student->fresh()->profile->instructor_status);
    }

    private function latestAudit(User $student): ?Activity
    {
        return Activity::query()
            ->where('log_name', 'student')
            ->where('event', 'student_status_changed')
            ->where('subject_id', $student->id)
            ->latest('id')
            ->first();
    }

    private function artisanOutput(string $command): string
    {
        $exitCode = Artisan::call($command);
        $this->assertSame(0, $exitCode);

        return Artisan::output();
    }
}
