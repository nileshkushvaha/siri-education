<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_receives_lesson_permissions_after_seeding(): void
    {
        $this->seed(LessonPermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $lesson = Lesson::factory()->create();

        $this->assertTrue($manager->can('viewAny', Lesson::class));
        $this->assertTrue($manager->can('view', $lesson));
        $this->assertTrue($manager->can('markAttendance', $lesson));
        $this->assertTrue($manager->can('complete', $lesson));
        $this->assertTrue($manager->can('dispute', $lesson));
        $this->assertTrue($manager->can('cancel', $lesson));

        // Lessons are engine-created; force delete stays super_admin-only.
        $this->assertFalse($manager->can('create', Lesson::class));
        $this->assertFalse($manager->can('forceDelete', $lesson));
    }

    public function test_participants_manage_their_own_lesson_without_permissions(): void
    {
        $lesson = Lesson::factory()->create();
        $instructor = $lesson->instructor;
        $student = $lesson->student;
        $stranger = User::factory()->create();

        $this->assertTrue($instructor->can('view', $lesson));
        $this->assertTrue($instructor->can('markAttendance', $lesson));
        $this->assertTrue($instructor->can('complete', $lesson));
        $this->assertTrue($student->can('view', $lesson));
        $this->assertTrue($student->can('dispute', $lesson));

        $this->assertFalse($student->can('complete', $lesson));
        $this->assertFalse($stranger->can('view', $lesson));
        $this->assertFalse($stranger->can('markAttendance', $lesson));
    }

    public function test_seeding_is_idempotent_and_plain_users_stay_denied(): void
    {
        $this->seed(LessonPermissionSeeder::class);
        $this->seed(LessonPermissionSeeder::class);

        $user = User::factory()->create();

        $this->assertFalse($user->can('viewAny', Lesson::class));
    }
}
