<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\User;
use App\Services\Student\StudentFavoriteInstructorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentFavoriteInstructorTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active]); // interactive student actions require Active status.
    }

    public function test_student_can_favorite_and_remove_bookable_instructor(): void
    {
        $instructor = $this->makeInstructor(InstructorStatus::Approved);

        app(StudentFavoriteInstructorService::class)->favorite($this->student, $instructor);
        app(StudentFavoriteInstructorService::class)->favorite($this->student, $instructor);

        $this->assertDatabaseCount('student_favorite_instructors', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'student_favorite_instructor_added']);

        app(StudentFavoriteInstructorService::class)->unfavorite($this->student, $instructor);

        $this->assertDatabaseMissing('student_favorite_instructors', [
            'student_user_id' => $this->student->id,
            'instructor_user_id' => $instructor->id,
        ]);
    }

    public function test_student_cannot_favorite_non_bookable_instructor_or_self(): void
    {
        $instructor = $this->makeInstructor(InstructorStatus::Suspended);

        $this->expectException(ValidationException::class);
        app(StudentFavoriteInstructorService::class)->favorite($this->student, $instructor);
    }

    public function test_favorite_row_remains_harmless_when_instructor_becomes_non_bookable(): void
    {
        $instructor = $this->makeInstructor(InstructorStatus::Approved);
        $service = app(StudentFavoriteInstructorService::class);

        $service->favorite($this->student, $instructor);
        $this->assertCount(1, $service->bookableFavorites($this->student));

        $instructor->profile->update(['instructor_status' => InstructorStatus::Suspended]);

        $this->assertDatabaseCount('student_favorite_instructors', 1);
        $this->assertCount(0, $service->bookableFavorites($this->student));
    }

    private function makeInstructor(InstructorStatus $status): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => $status,
        ]);

        return $instructor;
    }
}
