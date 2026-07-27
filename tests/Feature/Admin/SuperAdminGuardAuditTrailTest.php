<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS-23-7: a successful, GOVERNED role mutation
 * (another active Super Admin remains) is still audit-logged exactly
 * as before via the existing AuditTrailService/EditUser::afterSave()
 * pipeline — no new audit mechanism is added. A BLOCKED attempt
 * follows the project's existing convention for rejected business-rule
 * mutations elsewhere (e.g. rejected cancellation/
 * reschedule attempts): no dedicated audit row, since nothing was
 * actually applied.
 */
class SuperAdminGuardAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_a_successful_governed_demotion_is_still_audit_logged(): void
    {
        $target = $this->superAdmin();
        $other = $this->superAdmin();
        $this->actingAs($other);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'status' => 'active',
                'roles' => Role::query()->whereIn('name', ['manager'])->pluck('id')->all(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry = Activity::query()
            ->where('log_name', 'users')
            ->where('event', 'roles_updated')
            ->where('subject_id', $target->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'The successful role change must still be audit-logged as before.');
        $this->assertSame(['super_admin'], $entry->properties['roles_removed']);
        $this->assertSame($other->id, $entry->causer_id);
    }

    public function test_a_blocked_demotion_of_the_last_active_super_admin_writes_no_new_audit_row(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor);

        $before = Activity::query()->where('log_name', 'users')->where('event', 'roles_updated')->count();

        Livewire::test(EditUser::class, ['record' => $actor->getRouteKey()])
            ->fillForm([
                'name' => $actor->name,
                'email' => $actor->email,
                'status' => 'active',
                'roles' => Role::query()->whereIn('name', ['manager'])->pluck('id')->all(),
            ])
            ->call('save');

        $after = Activity::query()->where('log_name', 'users')->where('event', 'roles_updated')->count();

        $this->assertSame($before, $after, 'A rejected mutation must not produce a roles_updated audit row, since nothing was actually applied.');
        $this->assertTrue($actor->fresh()->hasRole('super_admin'));
    }
}
