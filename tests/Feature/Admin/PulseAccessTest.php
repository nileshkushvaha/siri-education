<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Policies\PulsePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `/pulse` is Pulse's own route, protected only by its
 * own `Authorize` middleware (`['web', Authorize::class]` — no `auth`
 * middleware), so denied requests get 403, not a login redirect.
 *
 * The Pulse migration (customized for this MySQL's missing
 * MD5() — see docs/pulse-monitoring.md) is in the normal migration
 * path, so the dashboard-render tests below run unconditionally — no
 * schema-based skip. A missing table correctly fails these tests
 * instead of silently skipping (see PulseMigrationCompatibilityTest for
 * the dedicated "migration must be present" check).
 */
class PulseAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $unauthorizedManager;

    private User $authorizedManager;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'pulse.view', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->unauthorizedManager = User::factory()->create(['status' => 'active']);
        $this->unauthorizedManager->assignRole('manager');

        $this->authorizedManager = User::factory()->create(['status' => 'active']);
        $this->authorizedManager->assignRole('manager');
        $this->authorizedManager->givePermissionTo('pulse.view');

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('super_admin');
    }

    public function test_guest_cannot_access_pulse(): void
    {
        $this->get('/pulse')->assertForbidden();
    }

    public function test_student_cannot_access_pulse(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->actingAs($student)->get('/pulse')->assertForbidden();
    }

    public function test_instructor_cannot_access_pulse(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)->get('/pulse')->assertForbidden();
    }

    public function test_unauthorized_admin_cannot_access_pulse(): void
    {
        $this->actingAs($this->unauthorizedManager)->get('/pulse')->assertForbidden();
    }

    public function test_authorized_manager_can_access_pulse(): void
    {
        $this->actingAs($this->authorizedManager)->get('/pulse')->assertOk();
    }

    public function test_super_admin_can_access_pulse(): void
    {
        $this->actingAs($this->superAdmin)->get('/pulse')->assertOk();
    }

    public function test_gate_denial_matches_http_forbidden_response(): void
    {
        $denies = Gate::forUser($this->unauthorizedManager)->denies('viewPulse');

        $this->assertTrue($denies);
        $this->actingAs($this->unauthorizedManager)->get('/pulse')->assertForbidden();
    }

    public function test_gate_allows_authorized_users(): void
    {
        $this->assertTrue(Gate::forUser($this->authorizedManager)->allows('viewPulse'));
        $this->assertTrue(Gate::forUser($this->superAdmin)->allows('viewPulse'));
        $this->assertTrue(app(PulsePolicy::class)->view($this->authorizedManager));
        $this->assertTrue(app(PulsePolicy::class)->view($this->superAdmin));
    }

    public function test_queue_monitor_page_remains_available(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/system/queue-monitor')
            ->assertOk();
    }
}
