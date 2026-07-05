<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    public function test_guest_is_redirected_from_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('auth.login'));
    }

    public function test_active_student_can_access_dashboard(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->actingAs($student)->get(route('dashboard'))->assertOk();
    }

    public function test_inactive_student_is_redirected_from_dashboard(): void
    {
        $student = User::factory()->create(['status' => 'inactive']);
        $student->assignRole('student');

        $this->actingAs($student)->get(route('dashboard'))->assertRedirect();
    }

    public function test_super_admin_is_redirected_away_by_frontend_portal_middleware(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect();
    }

    public function test_dashboard_contains_student_stat_cards(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->actingAs($student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee('Certificates');
    }
}
