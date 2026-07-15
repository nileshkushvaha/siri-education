<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LessonReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 17U.2 §11 — every current Phase 17 permission seeder
 * (Booking incl. archive/restore, Lesson, Review/moderation,
 * Review-report, Quality-dashboard/alert, Feedback, and the new
 * Review-settings/tags permissions) is now wired into the normal
 * production `DatabaseSeeder` path, runs idempotently, and leaves the
 * Spatie permission cache in a state where a freshly-seeded role's
 * grants are immediately effective (no stale-cache PermissionDoesNotExist).
 */
class DatabaseSeederPermissionWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_grants_the_full_review_lesson_and_feedback_permission_set_to_manager(): void
    {
        $this->seed();

        $manager = Role::where('name', 'manager')->firstOrFail();

        foreach ([
            'Archive:Booking', 'Restore:Booking',
            'ViewAny:Lesson', 'Complete:Lesson', 'Cancel:Lesson',
            'ViewAny:LessonReview', 'Moderate:LessonReview', 'Hide:LessonReview',
            'ViewAny:ReviewReport', 'Resolve:ReviewReport',
            'ViewAny:InstructorQualityAlert', 'Resolve:InstructorQualityAlert',
            'ViewQualityDashboard', 'ViewReviewModerationQueue', 'ViewReviewReports',
            'ViewAny:InstructorStudentFeedback',
            'settings.reviews_quality.view', 'settings.reviews_quality.update',
            'ViewAny:ReviewTag', 'Create:ReviewTag', 'Update:ReviewTag',
        ] as $permission) {
            $this->assertTrue($manager->hasPermissionTo($permission), "manager is missing {$permission}");
        }
    }

    public function test_database_seeder_grants_report_permission_to_student_and_instructor(): void
    {
        $this->seed();

        $this->assertTrue(Role::where('name', 'student')->firstOrFail()->hasPermissionTo('Report:LessonReview'));
        $this->assertTrue(Role::where('name', 'instructor')->firstOrFail()->hasPermissionTo('Report:LessonReview'));
    }

    public function test_running_the_full_database_seeder_twice_is_idempotent(): void
    {
        $this->seed();
        $countAfterFirst = Permission::query()->count();

        $this->seed();
        $countAfterSecond = Permission::query()->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_a_freshly_seeded_managers_permission_grant_is_immediately_usable_without_a_stale_cache(): void
    {
        $this->seed();

        // No manual cache-clear call here — proves the seeders
        // themselves already left the Spatie cache in a correct state.
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        $this->assertTrue($admin->can('viewAny', LessonReview::class));
    }

    public function test_permission_registrar_cache_is_not_stale_after_seeding(): void
    {
        $this->seed();

        $cached = app(PermissionRegistrar::class)->getPermissions();

        $this->assertTrue($cached->contains('name', 'Moderate:LessonReview'));
        $this->assertTrue($cached->contains('name', 'ViewAny:ReviewTag'));
    }
}
