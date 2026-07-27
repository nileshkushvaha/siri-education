<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS-1-16: suspended/archived students
 * must not establish (login) or retain (mid-session middleware) access
 * to the WHOLE account — including through another role on the same
 * user. A multi-role login exception (a bookable instructor capability
 * letting a suspended/archived student profile still authenticate) is
 * never an approved SRS deviation; the SRS's blanket "Suspended or
 * archived accounts shall be prevented from authenticating" is enforced
 * account-wide.
 */
class StudentLoginEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function studentWith(StudentStatus $status): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => $status]);

        return $student;
    }

    // ── 6. Suspended student cannot establish ordinary access ────────────────

    public function test_suspended_student_cannot_log_in(): void
    {
        $student = $this->studentWith(StudentStatus::Suspended);

        $response = $this->post(route('auth.login.store'), ['email' => $student->email, 'password' => 'password']);

        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();

        $message = session('errors')->get('email')[0];
        $this->assertStringNotContainsString('suspended', strtolower($message), 'Login failure message must be generic — never name the restriction.');
        $this->assertStringNotContainsString('archived', strtolower($message), 'Login failure message must be generic — never name the restriction.');
    }

    // ── 17. Archived student cannot establish ordinary access ───────────────

    public function test_archived_student_cannot_log_in(): void
    {
        $student = $this->studentWith(StudentStatus::Archived);

        $this->post(route('auth.login.store'), ['email' => $student->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email']);

        $this->assertGuest();
    }

    // ── 3. Active student can log in normally ────────────────────────────────

    public function test_active_student_can_log_in(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $this->post(route('auth.login.store'), ['email' => $student->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($student);
    }

    // ── 24/26. Multi-role: account-wide enforcement, no instructor bypass ───

    public function test_a_suspended_student_with_a_bookable_instructor_status_cannot_log_in(): void
    {
        $user = $this->studentWith(StudentStatus::Suspended);
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->post(route('auth.login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_an_archived_student_with_a_bookable_instructor_status_cannot_log_in(): void
    {
        $user = $this->studentWith(StudentStatus::Archived);
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Approved]);

        $this->post(route('auth.login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    /** Instructor lifecycle status itself is untouched by student suspension — only authentication is blocked. */
    public function test_instructor_lifecycle_state_is_unchanged_by_the_login_restriction(): void
    {
        $user = $this->studentWith(StudentStatus::Suspended);
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Active]);

        app(StudentLifecycleService::class)->blocksLogin($user->fresh());

        $this->assertSame(InstructorStatus::Active, $user->fresh()->profile->instructor_status);
        $this->assertTrue($user->fresh()->hasRole('instructor'));
    }

    // ── 7. Existing session is blocked mid-session (defense-in-depth) ───────

    public function test_a_student_suspended_mid_session_is_logged_out_on_the_next_request(): void
    {
        $student = $this->studentWith(StudentStatus::Active);

        $this->actingAs($student)->get('/dashboard')->assertOk();

        $student->profile()->update(['student_status' => StudentStatus::Suspended]);

        // Re-establish the guard against a freshly-loaded user: Laravel's
        // test kernel keeps the same container (and SessionGuard's
        // resolved-user cache) across chained requests within one test
        // method, unlike two genuinely separate real HTTP requests — the
        // production middleware itself needs no such nudge, since each
        // real request resolves the user (and this relation) fresh.
        $this->actingAs($student->fresh())
            ->get('/dashboard')
            ->assertRedirect(route('auth.login'));

        $this->assertGuest();
    }

    // ── 35. Admin/Super Admin access is unchanged ────────────────────────────

    public function test_an_account_with_no_student_role_is_never_blocked_by_the_student_lifecycle_check(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->assertFalse(app(StudentLifecycleService::class)->blocksLogin($admin));
    }
}
