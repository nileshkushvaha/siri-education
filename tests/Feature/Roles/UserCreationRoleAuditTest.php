<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24Q — GAP-011: role assignment on user creation happens via
 * Filament's own relationship field (UserForm's roles Select), before
 * CreateUser::afterCreate() runs. This mirrors EditUser::afterSave()'s
 * existing 'roles_updated' audit shape (unchanged by this phase — see
 * SuperAdminGuardAuditTrailTest) so both surfaces are equally auditable.
 */
class UserCreationRoleAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private int $managerRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('super_admin');
        $this->actingAs($this->superAdmin);

        $this->managerRoleId = $managerRole->id;
    }

    public function test_creating_a_user_with_a_role_records_one_roles_updated_event(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Admin User',
                'email' => 'new-admin-user@example.com',
                'password' => 'Sup3r$ecret!',
                'password_confirmation' => 'Sup3r$ecret!',
                'status' => 'active',
                'roles' => [$this->managerRoleId],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'new-admin-user@example.com')->firstOrFail();

        $events = Activity::query()
            ->where('log_name', 'users')
            ->where('event', 'roles_updated')
            ->where('subject_id', $user->id)
            ->get();

        $this->assertCount(1, $events);
        $activity = $events->first();
        $this->assertSame(['manager'], $activity->properties['roles_added']);
        $this->assertSame([], $activity->properties['roles_removed']);
        $this->assertSame($this->superAdmin->id, $activity->causer_id);
    }

    public function test_creating_a_user_with_no_role_records_no_roles_updated_event(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Roleless User',
                'email' => 'roleless-user@example.com',
                'password' => 'Sup3r$ecret!',
                'password_confirmation' => 'Sup3r$ecret!',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'roleless-user@example.com')->firstOrFail();

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'users')->where('event', 'roles_updated')->where('subject_id', $user->id)->count()
        );
    }
}
