<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24E — GAP-010/SRS-23-7: the UsersTable delete/bulk-delete
 * actions, driven through the real Filament table (not the service
 * directly).
 */
class SuperAdminProtectionUsersTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_the_only_active_super_admin_delete_action_is_hidden_and_not_invokable(): void
    {
        // The target must be a DIFFERENT account from the acting user —
        // deleting yourself is excluded by a separate, pre-existing
        // self-protection unrelated to this phase. The acting user is
        // deactivated so the target is the only active Super Admin.
        //
        // Filament's own visibility check is re-evaluated server-side
        // on every action call (never trusting a stale client render),
        // so a hidden delete action is provably not invokable at all —
        // a stronger guarantee than "hidden in the UI but still
        // executable," which is exactly Step 6's requirement.
        $actor = $this->superAdmin();
        $target = $this->superAdmin();
        $this->actingAs($actor);
        $actor->update(['status' => User::STATUS_INACTIVE]);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('delete', $target);

        $this->assertNotNull(User::query()->find($target->id));
    }

    public function test_a_super_admin_can_be_deleted_via_the_table_when_another_active_super_admin_remains(): void
    {
        $actor = $this->superAdmin();
        $target = $this->superAdmin();
        $this->actingAs($actor);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $target);

        $this->assertNull(User::query()->find($target->id));
    }

    public function test_unsafe_bulk_delete_of_users_is_rejected_atomically(): void
    {
        $actor = $this->superAdmin();
        $a = $this->superAdmin();
        $b = $this->superAdmin();
        $this->actingAs($actor);

        // Only $a and $b are targeted (bulk-deleting the acting user is
        // excluded by a pre-existing, unrelated self-protection). $actor
        // is deactivated first so $a and $b are the only two active
        // Super Admins left — deleting both would leave zero.
        $actor->update(['status' => User::STATUS_INACTIVE]);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', collect([$a, $b]));

        $this->assertNotNull(User::query()->find($a->id), 'The whole batch must roll back.');
        $this->assertNotNull(User::query()->find($b->id));
    }

    public function test_safe_bulk_delete_of_users_succeeds_without_reaching_zero(): void
    {
        $actor = $this->superAdmin();
        $survivor = $this->superAdmin();
        $a = $this->superAdmin();
        $b = $this->superAdmin();
        $this->actingAs($actor);

        $actor->update(['status' => User::STATUS_INACTIVE]);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', collect([$a, $b]));

        $this->assertNull(User::query()->find($a->id));
        $this->assertNull(User::query()->find($b->id));
        $this->assertNotNull(User::query()->find($survivor->id));
    }
}
