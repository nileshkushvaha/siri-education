<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Http\Middleware\EnsureStudentWorkspaceAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The student role is granted at registration only. An account that
 * holds `instructor` alone is told, on every student route, what it is
 * and how to learn instead (a separate student account) — while dual-role
 * and student-only accounts are untouched, and shared routes stay open.
 */
final class StudentWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    private const array STUDENT_ROUTES = [
        'booking.create', 'dashboard.my-bookings', 'dashboard.upcoming-classes', 'dashboard.bookings.index',
        'dashboard.wallet', 'dashboard.payments', 'dashboard.homework', 'dashboard.attendance', 'dashboard.progress',
        'dashboard.reviews', 'dashboard.refer-a-friend', 'dashboard.invoices', 'dashboard.learning-plans',
        'dashboard.learning-goals', 'dashboard.certificates', 'dashboard.orders', 'dashboard.wishlist',
        'dashboard.packages', 'dashboard.waitlist',
    ];

    private const array SHARED_ROUTES = [
        'dashboard', 'dashboard.notifications', 'dashboard.faqs', 'dashboard.support-cases', 'dashboard.messages',
        'dashboard.instructor.onboarding', 'dashboard.homework.resources.download', 'dashboard.media.download',
        'dashboard.meetings.join', 'dashboard.meetings.handoff',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function instructorOnly(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Draft]);

        return $user;
    }

    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function dualRole(): User
    {
        $user = $this->student();
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Active, 'student_status' => StudentStatus::Active]);

        return $user;
    }

    public function test_the_middleware_guards_exactly_the_student_routes(): void
    {
        foreach (self::STUDENT_ROUTES as $name) {
            $this->assertContains(EnsureStudentWorkspaceAccess::class, Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [], "{$name} must be gated");
        }

        foreach (self::SHARED_ROUTES as $name) {
            $this->assertNotContains(EnsureStudentWorkspaceAccess::class, Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [], "{$name} must stay shared");
        }
    }

    public function test_an_instructor_only_account_sees_the_explanation_page_on_student_routes(): void
    {
        $user = $this->instructorOnly();

        foreach (['booking.create', 'dashboard.my-bookings', 'dashboard.bookings.index', 'dashboard.wallet', 'dashboard.homework'] as $name) {
            $this->actingAs($user)
                ->get(route($name))
                ->assertStatus(403)
                ->assertSee('This is an instructor account')
                ->assertSee('separate student account')
                ->assertSee('different email address');
        }

        $this->actingAs($user)->getJson(route('dashboard.bookings.index'))->assertStatus(403);
    }

    public function test_an_instructor_only_account_keeps_the_shared_and_instructor_routes(): void
    {
        $user = $this->instructorOnly();

        foreach (['dashboard', 'dashboard.notifications', 'dashboard.support-cases', 'dashboard.messages', 'dashboard.instructor.onboarding'] as $name) {
            $this->actingAs($user)->get(route($name))->assertOk();
        }
    }

    public function test_student_and_dual_role_accounts_are_not_affected(): void
    {
        foreach ([$this->student(), $this->dualRole()] as $user) {
            foreach (['dashboard.my-bookings', 'dashboard.upcoming-classes', 'dashboard.homework'] as $name) {
                $this->actingAs($user)->get(route($name))->assertOk();
            }
        }
    }
}
