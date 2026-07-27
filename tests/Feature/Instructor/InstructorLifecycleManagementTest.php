<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Models\Activity;
use App\Models\User;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorLifecycleManagementTest extends TestCase
{
    use RefreshDatabase;

    private InstructorOnboardingService $onboarding;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        foreach ([
            InstructorOnboardingService::ACTIVATE_PERMISSION,
            InstructorOnboardingService::VACATION_PERMISSION,
            InstructorOnboardingService::SUSPEND_PERMISSION,
            InstructorOnboardingService::ARCHIVE_PERMISSION,
            InstructorOnboardingService::INTERVIEW_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->onboarding = app(InstructorOnboardingService::class);
    }

    // ── Activation ──────────────────────────────────────────────────

    public function test_approved_instructor_can_be_activated(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::ACTIVATE_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Approved);

        $profile = $this->onboarding->activate($instructor, $admin);

        $this->assertSame(InstructorStatus::Active, $profile->instructor_status);
        $this->assertActivityLogged('instructor_activated', $instructor, ['previous_status' => 'approved', 'new_status' => 'active']);
    }

    public function test_draft_instructor_cannot_be_activated(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::ACTIVATE_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Draft);

        $this->expectException(ValidationException::class);

        $this->onboarding->activate($instructor, $admin);
    }

    public function test_submitted_instructor_cannot_be_activated(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::ACTIVATE_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Submitted);

        $this->expectException(ValidationException::class);

        $this->onboarding->activate($instructor, $admin);
    }

    // ── Vacation ────────────────────────────────────────────────────

    public function test_active_instructor_can_start_vacation(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::VACATION_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Active);

        $profile = $this->onboarding->setVacation($instructor, $admin);

        $this->assertSame(InstructorStatus::Vacation, $profile->instructor_status);
        $this->assertActivityLogged('instructor_vacation_started', $instructor);
    }

    public function test_vacationing_instructor_can_resume_to_active(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::VACATION_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Vacation);

        $profile = $this->onboarding->resumeFromVacation($instructor, $admin);

        $this->assertSame(InstructorStatus::Active, $profile->instructor_status);
        $this->assertActivityLogged('instructor_vacation_ended', $instructor);
    }

    // ── Suspension ──────────────────────────────────────────────────

    public function test_active_instructor_can_be_suspended(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::SUSPEND_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Active);

        $profile = $this->onboarding->suspend($instructor, $admin, 'Repeated policy violations.');

        $this->assertSame(InstructorStatus::Suspended, $profile->instructor_status);
        $this->assertSame('Repeated policy violations.', $profile->instructor_review_reason);
        $this->assertActivityLogged('instructor_suspended', $instructor, ['reason' => 'Repeated policy violations.']);
    }

    public function test_suspension_requires_a_reason(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::SUSPEND_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Active);

        $this->expectException(ValidationException::class);

        $this->onboarding->suspend($instructor, $admin, '   ');
    }

    public function test_suspended_instructor_cannot_be_publicly_booked(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::SUSPEND_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Active);
        $instructor->profile()->update(['profile_visibility' => 'public']);
        $this->onboarding->suspend($instructor->fresh(), $admin, 'Under investigation.');

        $this->get(route('instructors.show', $instructor->fresh()))
            ->assertForbidden();
    }

    // ── Archive ─────────────────────────────────────────────────────

    public function test_suspended_instructor_can_be_archived(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::ARCHIVE_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Suspended);

        $profile = $this->onboarding->archive($instructor, $admin, 'No response after 90 days.');

        $this->assertSame(InstructorStatus::Archived, $profile->instructor_status);
        $this->assertActivityLogged('instructor_archived', $instructor);
    }

    public function test_archived_instructor_cannot_be_activated_or_resumed(): void
    {
        $activateAdmin = $this->adminWith(InstructorOnboardingService::ACTIVATE_PERMISSION);
        $vacationAdmin = $this->adminWith(InstructorOnboardingService::VACATION_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::Archived);

        try {
            $this->onboarding->activate($instructor, $activateAdmin);
            $this->fail('Archived instructor must not be activatable.');
        } catch (ValidationException) {
            // expected
        }

        try {
            $this->onboarding->resumeFromVacation($instructor, $vacationAdmin);
            $this->fail('Archived instructor must not be resumable.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(InstructorStatus::Archived, $instructor->fresh()->profile->instructor_status);
    }

    // ── Interview Required ─────────────────────────────────────────

    public function test_under_review_instructor_can_be_marked_interview_required(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::INTERVIEW_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::UnderReview);

        $profile = $this->onboarding->markInterviewRequired($instructor, $admin, 'Technical interview required before approval.');

        $this->assertSame(InstructorStatus::InterviewRequired, $profile->instructor_status);
        $this->assertActivityLogged('instructor_interview_required', $instructor, ['reason' => 'Technical interview required before approval.']);
    }

    public function test_interview_required_reason_is_mandatory(): void
    {
        $admin = $this->adminWith(InstructorOnboardingService::INTERVIEW_PERMISSION);
        $instructor = $this->instructorAt(InstructorStatus::UnderReview);

        $this->expectException(ValidationException::class);

        $this->onboarding->markInterviewRequired($instructor, $admin, '');
    }

    // ── Permissions ─────────────────────────────────────────────────

    public function test_admin_without_the_dedicated_permission_is_blocked(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        // Deliberately no ACTIVATE_PERMISSION granted.
        $instructor = $this->instructorAt(InstructorStatus::Approved);

        $this->expectException(AuthorizationException::class);

        $this->onboarding->activate($instructor, $admin);
    }

    public function test_super_admin_is_always_allowed_without_an_explicit_grant(): void
    {
        $superAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $superAdmin->assignRole('super_admin');
        // No explicit permission grant — Gate::before()'s isSuperAdmin() bypass must apply.
        $instructor = $this->instructorAt(InstructorStatus::Approved);

        $profile = $this->onboarding->activate($instructor, $superAdmin);

        $this->assertSame(InstructorStatus::Active, $profile->instructor_status);
    }

    // ── Part 9 — public marketplace visibility across every status ──

    public function test_public_profile_visibility_matches_the_expected_status_matrix(): void
    {
        $expectedVisible = [
            InstructorStatus::Approved,
            InstructorStatus::Active,
            // A Vacation instructor's profile stays visible
            // ("temporarily unavailable", booking disabled), unlike an
            // unapproved/suspended/archived instructor's.
            InstructorStatus::Vacation,
        ];

        foreach (InstructorStatus::cases() as $status) {
            $instructor = $this->instructorAt($status);
            $instructor->profile()->update(['profile_visibility' => 'public']);

            $response = $this->get(route('instructors.show', $instructor->fresh()));

            if (in_array($status, $expectedVisible, true)) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }
    }

    private function adminWith(string $permission): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        $admin->givePermissionTo($permission);

        return $admin;
    }

    private function instructorAt(InstructorStatus $status): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => $status]);

        return $instructor->fresh();
    }

    private function assertActivityLogged(string $event, User $instructor, array $properties = []): void
    {
        $activity = Activity::query()
            ->where('log_name', 'instructor')
            ->where('event', $event)
            ->where('subject_id', $instructor->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, "Expected an audit entry for event [{$event}].");

        foreach ($properties as $key => $value) {
            $this->assertSame($value, $activity->properties[$key] ?? null);
        }
    }
}
