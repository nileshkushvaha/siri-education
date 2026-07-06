<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\AcademicStatus;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Language;
use App\Models\Subject;
use App\Models\User;
use App\Services\Student\StudentProfilePreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentProfilePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    public function test_student_preferences_use_profile_fields_and_subject_pivot(): void
    {
        $level = AcademicLevel::create(['name' => 'High School', 'slug' => 'high-school']);
        $language = Language::create(['name' => 'English', 'code' => 'en', 'status' => 'active']);
        $category = AcademicCategory::create(['name' => 'Science', 'slug' => 'science']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Physics', 'slug' => 'physics']);

        app(StudentProfilePreferenceService::class)->update($this->student, [
            'student_academic_level_id' => $level->id,
            'student_preferred_language_id' => $language->id,
            'preferred_subject_ids' => [$subject->id, $subject->id],
        ]);

        $this->student->refresh();

        $this->assertSame($level->id, $this->student->profile->student_academic_level_id);
        $this->assertSame($language->id, $this->student->profile->student_preferred_language_id);
        $this->assertTrue($this->student->preferredSubjects->contains($subject));
        $this->assertDatabaseCount('student_preferred_subjects', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'student_preferences_updated']);
    }

    public function test_inactive_subject_is_rejected_for_preferences(): void
    {
        $category = AcademicCategory::create(['name' => 'Science', 'slug' => 'science']);
        $subject = Subject::create([
            'academic_category_id' => $category->id,
            'name' => 'Archived Physics',
            'slug' => 'archived-physics',
            'status' => AcademicStatus::Archived,
        ]);

        $this->expectException(ValidationException::class);

        app(StudentProfilePreferenceService::class)->update($this->student, [
            'preferred_subject_ids' => [$subject->id],
        ]);
    }

    public function test_no_preferred_subject_json_column_is_created(): void
    {
        $this->assertTrue(Schema::hasTable('student_preferred_subjects'));
        $this->assertFalse(Schema::hasColumn('user_profiles', 'student_preferred_subject_ids'));
    }
}
