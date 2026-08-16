<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\InstructorSubjectTopic;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\TeacherSubject;
use App\Models\User;
use Database\Seeders\InstructorSubjectAssignmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorSubjectAssignmentSeederTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(string $email): User
    {
        $role = Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $user = User::factory()->create(['email' => $email, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function seedCatalogue(): Subject
    {
        AcademicLevel::query()->create([
            'name' => 'Middle School',
            'slug' => 'middle-school',
            'min_grade' => 6,
            'max_grade' => 8,
            'status' => 'active',
        ]);
        AcademicLevel::query()->create([
            'name' => 'High School',
            'slug' => 'high-school',
            'min_grade' => 9,
            'max_grade' => 12,
            'status' => 'active',
        ]);

        $category = AcademicCategory::query()->firstOrCreate(
            ['slug' => 'general'],
            ['name' => 'General'],
        );

        $subject = Subject::query()->create([
            'academic_category_id' => $category->id,
            'name' => 'Mathematics',
            'slug' => 'mathematics',
            'status' => 'active',
        ]);

        SubjectTopic::factory()->count(2)->create([
            'subject_id' => $subject->id,
            'status' => 'active',
        ]);

        return $subject;
    }

    private function runSeeder(): void
    {
        (new InstructorSubjectAssignmentSeeder)->run();
    }

    public function test_it_assigns_random_subjects_to_an_instructor_that_has_none(): void
    {
        $this->seedCatalogue();
        $instructor = $this->instructor('someone@staging-domain.test');

        $this->runSeeder();

        $this->assertGreaterThan(
            0,
            TeacherSubject::query()->where('teacher_id', $instructor->id)->count(),
            'An instructor with no subjects should receive a random selection.'
        );
    }

    public function test_it_only_touches_users_with_the_instructor_role(): void
    {
        $this->seedCatalogue();
        $student = User::factory()->create(['status' => 'active']);

        $this->runSeeder();

        $this->assertSame(
            0,
            TeacherSubject::query()->where('teacher_id', $student->id)->count(),
            'Non-instructors must never be assigned subjects.'
        );
    }

    public function test_it_works_for_many_instructors_regardless_of_email_domain(): void
    {
        $this->seedCatalogue();

        $instructors = collect([
            'a@staging.example.org',
            'b@client-domain.com',
            'c@another.co.in',
            'd@live-site.net',
        ])->map(fn (string $email) => $this->instructor($email));

        $this->runSeeder();

        foreach ($instructors as $instructor) {
            $this->assertGreaterThan(
                0,
                TeacherSubject::query()->where('teacher_id', $instructor->id)->count(),
                "No subjects assigned to {$instructor->email}"
            );
            $this->assertGreaterThan(
                0,
                InstructorSubjectTopic::query()->where('teacher_id', $instructor->id)->count(),
                "No topic records backfilled for {$instructor->email}"
            );
        }
    }

    public function test_it_never_overwrites_an_instructors_existing_subjects(): void
    {
        $subject = $this->seedCatalogue();
        $instructor = $this->instructor('real@client.com');

        TeacherSubject::query()->create([
            'teacher_id' => $instructor->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
            'grade_from' => 9,
            'grade_to' => 12,
        ]);

        $this->runSeeder();

        $subjects = TeacherSubject::query()->where('teacher_id', $instructor->id)->get();
        $this->assertCount(1, $subjects, 'Existing assignments must not gain random extras.');
        $this->assertSame(9, $subjects->first()->grade_from);
    }

    public function test_running_it_twice_adds_no_further_subjects(): void
    {
        $this->seedCatalogue();
        $instructor = $this->instructor('rerun@client.com');

        $this->runSeeder();
        $afterFirst = TeacherSubject::query()->where('teacher_id', $instructor->id)->count();

        $this->runSeeder();
        $afterSecond = TeacherSubject::query()->where('teacher_id', $instructor->id)->count();

        $this->assertSame($afterFirst, $afterSecond, 'Seeder must be idempotent on re-run.');
    }

    public function test_it_resolves_levels_from_the_assignment_grade_range(): void
    {
        $subject = $this->seedCatalogue();
        $instructor = $this->instructor('middle@client.com');

        TeacherSubject::query()->create([
            'teacher_id' => $instructor->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
            'grade_from' => 6,
            'grade_to' => 8,
        ]);

        $this->runSeeder();

        $levelIds = InstructorSubjectTopic::query()
            ->where('teacher_id', $instructor->id)
            ->pluck('academic_level_id')
            ->unique();

        $highSchoolId = AcademicLevel::query()->where('slug', 'high-school')->value('id');

        $this->assertNotContains(
            $highSchoolId,
            $levelIds->all(),
            'A grades 6-8 assignment must not produce high-school level rows.'
        );
    }
}
