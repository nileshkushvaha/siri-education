<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Filament\Resources\StudentLearningGoals\StudentLearningGoalResource;
use App\Models\StudentLearningGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentDashboardFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_learning_goal_and_favorites_pages_are_protected(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->get(route('dashboard.learning-goals'))->assertRedirect(route('auth.login'));
        $this->get(route('dashboard.wishlist'))->assertRedirect(route('auth.login'));

        $this->actingAs($student)
            ->get(route('dashboard.learning-goals'))
            ->assertOk()
            ->assertSee('Learning Goals');

        $this->actingAs($student)
            ->get(route('dashboard.wishlist'))
            ->assertOk()
            ->assertSee('Favorite Instructors');
    }

    public function test_no_duplicate_student_identity_tables_are_created(): void
    {
        $this->assertFalse(Schema::hasTable('students'));
        $this->assertFalse(Schema::hasTable('student_profiles'));
        $this->assertFalse(class_exists('App\\Models\\Student'));
        $this->assertFalse(class_exists('App\\Models\\StudentProfile'));
        $this->assertFalse(class_exists('App\\Filament\\Resources\\Students\\StudentResource'));
        $this->assertTrue(Schema::hasTable('student_learning_goals'));
        $this->assertTrue(Schema::hasTable('student_favorite_instructors'));
        $this->assertTrue(Schema::hasTable('student_preferred_subjects'));
    }

    public function test_learning_goal_resource_is_permission_backed_without_student_resource(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        $this->actingAs($manager);
        $this->assertFalse(StudentLearningGoalResource::canViewAny());

        $permission = Permission::firstOrCreate(['name' => 'ViewAny:StudentLearningGoal', 'guard_name' => 'web']);
        $manager->givePermissionTo($permission);

        $this->assertTrue(StudentLearningGoalResource::canViewAny());
        $this->assertSame(StudentLearningGoal::class, StudentLearningGoalResource::getModel());
    }
}
