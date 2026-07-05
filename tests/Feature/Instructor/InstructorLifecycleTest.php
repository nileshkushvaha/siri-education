<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\Repositories\TeacherCandidateRepository;
use App\Enums\InstructorStatus;
use App\Models\TeacherSubject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Instructor application creation must not grant teaching access until
 * approval: only Approved/Active make an instructor bookable, publicly
 * listed, and visible on their public profile. Every other lifecycle
 * state (draft, submitted, under_review, documents_pending,
 * interview_required, vacation, suspended, archived, rejected) must be
 * excluded from all three surfaces.
 */
class InstructorLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function makeInstructor(InstructorStatus $status): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => $status,
        ]);
        $user->assignRole('instructor');

        return $user;
    }

    #[DataProvider('nonBookableStatusProvider')]
    public function test_non_bookable_instructor_excluded_from_public_listing(InstructorStatus $status): void
    {
        $instructor = $this->makeInstructor($status);

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertDontSee($instructor->name);
    }

    #[DataProvider('nonBookableStatusProvider')]
    public function test_non_bookable_instructor_public_profile_is_forbidden(InstructorStatus $status): void
    {
        $instructor = $this->makeInstructor($status);

        $this->get(route('instructors.show', $instructor))->assertForbidden();
    }

    #[DataProvider('nonBookableStatusProvider')]
    public function test_non_bookable_instructor_is_not_a_booking_candidate(InstructorStatus $status): void
    {
        $instructor = $this->makeInstructor($status);

        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => 'mathematics',
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $repository = app(TeacherCandidateRepository::class);

        $this->assertFalse($repository->isApprovedTeacher($instructor->id));
        $this->assertTrue(
            $repository->eligible($this->makeCriteria())->isEmpty(),
        );
    }

    public static function nonBookableStatusProvider(): array
    {
        return array_map(
            fn (InstructorStatus $status): array => [$status],
            array_values(array_filter(
                InstructorStatus::cases(),
                fn (InstructorStatus $status): bool => ! in_array($status, InstructorStatus::bookable(), true),
            )),
        );
    }

    #[DataProvider('bookableStatusProvider')]
    public function test_bookable_instructor_appears_in_public_listing(InstructorStatus $status): void
    {
        $instructor = $this->makeInstructor($status);

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertSee($instructor->name);
    }

    #[DataProvider('bookableStatusProvider')]
    public function test_bookable_instructor_is_a_booking_candidate(InstructorStatus $status): void
    {
        $instructor = $this->makeInstructor($status);

        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => 'mathematics',
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $repository = app(TeacherCandidateRepository::class);

        $this->assertTrue($repository->isApprovedTeacher($instructor->id));
    }

    public static function bookableStatusProvider(): array
    {
        return array_map(fn (InstructorStatus $status): array => [$status], InstructorStatus::bookable());
    }

    public function test_instructor_status_null_by_default_for_non_applicants(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->assertNull($user->profile->instructor_status);
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
