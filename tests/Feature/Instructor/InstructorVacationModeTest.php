<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\Repositories\TeacherCandidateRepository;
use App\Enums\InstructorStatus;
use App\Livewire\Frontend\Instructor\VacationModeManager;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\Account\AccountMenuService;
use App\Services\Instructor\InstructorOnboardingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorVacationModeTest extends TestCase
{
    use RefreshDatabase;

    private InstructorOnboardingService $onboarding;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->onboarding = app(InstructorOnboardingService::class);
    }

    // ── 1. Instructor enables vacation ───────────────────────────────

    public function test_active_instructor_can_enable_vacation_mode(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);

        Livewire::actingAs($instructor)
            ->test(VacationModeManager::class)
            ->call('confirmEnable')
            ->call('enableVacation')
            ->assertHasNoErrors();

        $this->assertSame(InstructorStatus::Vacation, $instructor->fresh()->profile->instructor_status);
        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'instructor')->where('event', 'instructor_vacation_started')->count(),
        );
    }

    public function test_vacation_page_shows_enable_confirmation_copy(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);

        Livewire::actingAs($instructor)
            ->test(VacationModeManager::class)
            ->assertSee('Available')
            ->call('confirmEnable')
            ->assertSee('New students will not be able to book lessons while vacation mode is active.')
            ->assertSee('Existing scheduled lessons are not affected.');
    }

    // ── 2. Instructor resumes ────────────────────────────────────────

    public function test_vacationing_instructor_can_resume_teaching(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Vacation);

        Livewire::actingAs($instructor)
            ->test(VacationModeManager::class)
            ->call('confirmResume')
            ->call('resumeTeaching')
            ->assertHasNoErrors();

        $this->assertSame(InstructorStatus::Active, $instructor->fresh()->profile->instructor_status);
        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'instructor')->where('event', 'instructor_vacation_ended')->count(),
        );
    }

    // ── 3. Ownership ──────────────────────────────────────────────────

    public function test_instructor_a_cannot_modify_instructor_b_vacation_status(): void
    {
        $instructorA = $this->instructorAt(InstructorStatus::Active);
        $instructorB = $this->instructorAt(InstructorStatus::Active);

        // The service call is always self-scoped — there is no route
        // parameter or input field naming "which instructor" to attack;
        // this proves the guarantee directly at the service boundary.
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->onboarding->setVacation($instructorB, $instructorA);
    }

    public function test_instructor_without_permission_cannot_set_vacation_for_another_instructor(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->assignRole('instructor');
        $instructor = $this->instructorAt(InstructorStatus::Active);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->onboarding->setVacation($instructor, $actor);
    }

    // ── 4. Existing lessons unaffected ───────────────────────────────

    public function test_existing_scheduled_lesson_is_unaffected_by_enabling_vacation(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $booking = Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);
        $lesson = Lesson::factory()->create([
            'booking_id' => $booking->id,
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);

        Livewire::actingAs($instructor)
            ->test(VacationModeManager::class)
            ->call('confirmEnable')
            ->call('enableVacation');

        $this->assertSame(\App\Lessons\Enums\LessonStatus::Scheduled, $lesson->fresh()->status);
        $this->assertSame(\App\Booking\Enums\BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_existing_availability_schedule_is_preserved_when_vacation_enabled(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);
        $window = TeacherAvailability::factory()->create(['teacher_id' => $instructor->id, 'is_active' => true]);

        Livewire::actingAs($instructor)
            ->test(VacationModeManager::class)
            ->call('confirmEnable')
            ->call('enableVacation');

        $this->assertDatabaseHas('teacher_availability', ['id' => $window->id, 'is_active' => true]);
    }

    // ── 5. Public booking behavior ───────────────────────────────────

    public function test_vacation_instructor_is_not_a_booking_candidate(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Vacation);
        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => 'mathematics',
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $repository = app(TeacherCandidateRepository::class);

        $this->assertFalse($repository->isApprovedTeacher($instructor->id));
        $this->assertTrue($repository->eligible($this->makeCriteria())->isEmpty());
    }

    public function test_active_instructor_is_a_booking_candidate(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);
        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => 'mathematics',
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $repository = app(TeacherCandidateRepository::class);

        $this->assertTrue($repository->isApprovedTeacher($instructor->id));
    }

    public function test_vacation_instructor_public_profile_shows_unavailable_not_booking_ctas(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Vacation);
        $instructor->profile()->update(['profile_visibility' => 'public']);

        $response = $this->get(route('instructors.show', $instructor->fresh()))->assertOk();

        $response->assertSee('Instructor temporarily unavailable');
        $response->assertSee('Booking disabled');
        $response->assertDontSee('Book a Lesson');
        $response->assertDontSee('Book a Free Demo');
    }

    public function test_active_instructor_public_profile_shows_booking_ctas(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);
        $instructor->profile()->update(['profile_visibility' => 'public']);

        $response = $this->get(route('instructors.show', $instructor->fresh()))->assertOk();

        $response->assertSee('Book a Lesson');
        $response->assertDontSee('Instructor temporarily unavailable');
    }

    public function test_vacation_instructor_excluded_from_public_listing(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Vacation);
        $instructor->profile()->update(['profile_visibility' => 'public']);

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertDontSee($instructor->name);
    }

    // ── 6. Concurrency (shares transitionStatus()'s row-lock guard,
    //      already proven under true cross-process concurrency for
    //      activate() by InstructorLifecycleRaceTest) ────────────────

    public function test_second_concurrent_set_vacation_call_is_rejected_after_the_first_succeeds(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);

        $this->onboarding->setVacation($instructor, $instructor);

        $this->expectException(ValidationException::class);
        $this->onboarding->setVacation($instructor->fresh(), $instructor->fresh());
    }

    // ── UI states ─────────────────────────────────────────────────────

    public function test_suspended_instructor_sees_restricted_message_with_no_actions(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Suspended);

        Livewire::actingAs($instructor)
            ->test(VacationModeManager::class)
            ->assertSee('Your account is currently restricted. Contact support.')
            ->assertDontSee('Enable Vacation Mode')
            ->assertDontSee('Resume Teaching');
    }

    public function test_archived_instructor_has_no_access_to_vacation_page(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Archived);

        $this->actingAs($instructor)
            ->get(route('dashboard.instructor.vacation'))
            ->assertForbidden();
    }

    public function test_student_cannot_access_instructor_vacation_page(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->actingAs($student)
            ->get(route('dashboard.instructor.vacation'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_vacation_page(): void
    {
        $this->get(route('dashboard.instructor.vacation'))->assertRedirect(route('auth.login'));
    }

    // ── Navigation & dashboard integration ────────────────────────────

    public function test_instructor_navigation_includes_vacation_mode(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);

        $items = app(AccountMenuService::class)->items($instructor);
        $labels = collect($items)->flatMap(fn (array $group) => collect($group['items'])->pluck('label'))->all();

        $this->assertContains('Vacation Mode', $labels);
    }

    public function test_dashboard_shows_vacation_active_status_and_link(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Vacation);

        $response = $this->actingAs($instructor)->get(route('dashboard'))->assertOk();

        $response->assertSee('Vacation Mode Active');
        $response->assertSee(route('dashboard.instructor.vacation'), false);
    }

    public function test_availability_page_shows_vacation_banner_when_active(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Vacation);

        $response = $this->actingAs($instructor)->get(route('dashboard.instructor.availability'))->assertOk();

        $response->assertSee('Vacation mode is enabled.');
        $response->assertSee('Your weekly availability is saved but new bookings are paused.');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function instructorAt(InstructorStatus $status): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => $status]);

        return $instructor->fresh();
    }

    private function makeCriteria(): AssignmentCriteriaData
    {
        return new AssignmentCriteriaData(
            typeKey: 'one_on_one',
            subject: 'mathematics',
            grade: 5,
            startsAt: CarbonImmutable::now()->addDay(),
            durationMinutes: 30,
        );
    }
}
