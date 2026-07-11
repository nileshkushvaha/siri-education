<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Models\InstructorCompensationAgreement;
use App\Models\User;
use Database\Seeders\InstructorCompensationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 14.2 §12/§13 — the compensation admin surface: no percentage
 * controls anywhere, agreement management is permission-gated, and the
 * global settings page points to per-instructor agreements instead of
 * exposing any global amount.
 */
class InstructorCompensationFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agreement_resource_renders_for_super_admin(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        InstructorCompensationAgreement::factory()->create();

        $this->actingAs($admin)->get('/admin/instructor-compensation-agreements')->assertOk();
    }

    public function test_agreement_resource_renders_for_seeded_manager(): void
    {
        $this->seed(InstructorCompensationPermissionSeeder::class);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        InstructorCompensationAgreement::factory()->create();

        $this->actingAs($manager)->get('/admin/instructor-compensation-agreements')->assertOk();
    }

    public function test_agreement_resource_denies_users_without_permissions(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)->get('/admin/instructor-compensation-agreements')->assertForbidden();
    }

    public function test_permission_seeder_is_idempotent_and_grants_manager(): void
    {
        $this->seed(InstructorCompensationPermissionSeeder::class);
        $this->seed(InstructorCompensationPermissionSeeder::class);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        foreach (['ViewAny', 'View', 'Create', 'Schedule', 'Activate', 'End', 'Cancel', 'ViewHistory', 'Configure'] as $verb) {
            $this->assertTrue($manager->hasPermissionTo($verb.':InstructorCompensationAgreement'), $verb);
        }

        $this->assertSame(9, Permission::query()->where('name', 'like', '%InstructorCompensationAgreement')->count());
    }

    public function test_settings_page_has_no_percentage_controls_and_links_to_agreements(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        $response = $this->actingAs($admin)->get('/admin/settings/instructor-earnings');

        $response->assertOk()
            // The commission controls are gone from the active UI…
            ->assertDontSee('Percentage of student price')
            ->assertDontSee('Instructor percentage')
            ->assertDontSee('Default calculation')
            ->assertDontSee('Fixed-rate currency')
            // …replaced by per-instructor agreement management.
            ->assertSee('Instructor Compensation')
            ->assertSee('Manage Instructor Compensation')
            ->assertSee('Active agreements')
            ->assertSee('Instructors missing active agreements');
    }

    public function test_exceptions_page_renders_for_authorized_admins_and_denies_others(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)->get('/admin/instructor-compensation-exceptions')->assertOk();

        $this->seed(InstructorCompensationPermissionSeeder::class);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $this->actingAs($manager)->get('/admin/instructor-compensation-exceptions')->assertOk();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actingAs($user)->get('/admin/instructor-compensation-exceptions')->assertForbidden();
    }

    public function test_agreement_table_hides_internal_reason_from_serialization(): void
    {
        $agreement = InstructorCompensationAgreement::factory()->create([
            'internal_reason' => 'SECRET-DECISION-CONTEXT',
        ]);

        $this->assertStringNotContainsString('SECRET-DECISION-CONTEXT', json_encode($agreement->toArray()));
    }
}
