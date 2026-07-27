<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Exceptions\CanonicalSuperAdminRoleProtectedException;
use App\Exceptions\LastActiveSuperAdminException;
use App\Models\User;
use App\Services\Admin\SuperAdminGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS-23-7: pure/integration tests for the central
 * SuperAdminGuardService, exercised directly (never through Filament),
 * proving the invariant is enforced at the service layer regardless of
 * caller — satisfies "direct supported service invocation cannot
 * bypass protection."
 */
class SuperAdminGuardServiceTest extends TestCase
{
    use RefreshDatabase;

    private SuperAdminGuardService $guard;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $this->guard = app(SuperAdminGuardService::class);
    }

    private function superAdmin(string $status = User::STATUS_ACTIVE): User
    {
        $user = User::factory()->create(['status' => $status]);
        $user->assignRole('super_admin');

        return $user;
    }

    // ── isActiveSuperAdmin / countOtherActiveSuperAdmins / isLastActiveSuperAdmin ─

    public function test_an_active_user_with_the_role_counts_as_an_active_super_admin(): void
    {
        $admin = $this->superAdmin();

        $this->assertTrue($this->guard->isActiveSuperAdmin($admin));
    }

    public function test_an_inactive_blocked_or_suspended_super_admin_does_not_count(): void
    {
        foreach ([User::STATUS_INACTIVE, User::STATUS_BLOCKED, User::STATUS_SUSPENDED, User::STATUS_PENDING] as $status) {
            $admin = $this->superAdmin($status);
            $this->assertFalse($this->guard->isActiveSuperAdmin($admin), "status={$status} must not count");
        }
    }

    public function test_an_active_non_super_admin_does_not_count(): void
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        $this->assertFalse($this->guard->isActiveSuperAdmin($manager));
        $this->assertSame(0, $this->guard->countOtherActiveSuperAdmins($manager));
    }

    public function test_is_last_active_super_admin_is_true_when_no_others_remain(): void
    {
        $admin = $this->superAdmin();

        $this->assertTrue($this->guard->isLastActiveSuperAdmin($admin));
    }

    public function test_is_last_active_super_admin_is_false_when_another_remains(): void
    {
        $a = $this->superAdmin();
        $this->superAdmin();

        $this->assertFalse($this->guard->isLastActiveSuperAdmin($a));
    }

    // ── protect(): role removal ──────────────────────────────────────────────

    public function test_the_only_active_super_admin_cannot_have_the_role_removed(): void
    {
        $admin = $this->superAdmin();

        $this->expectException(LastActiveSuperAdminException::class);
        $this->expectExceptionMessage('You cannot remove access from the last active Super Admin. Assign or activate another Super Admin first.');

        $this->guard->protect($admin, fn (User $user) => $user->removeRole('super_admin'));
    }

    public function test_the_only_active_super_admin_cannot_be_demoted_through_syncroles(): void
    {
        $admin = $this->superAdmin();

        $this->expectException(LastActiveSuperAdminException::class);

        $this->guard->protect($admin, fn (User $user) => $user->syncRoles(['manager']));
    }

    public function test_a_super_admin_can_be_demoted_when_another_active_super_admin_remains(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();

        $this->guard->protect($admin, fn (User $user) => $user->removeRole('super_admin'));

        $this->assertFalse($admin->fresh()->hasRole('super_admin'));
    }

    // ── protect(): status/deactivation/block/suspend ────────────────────────

    public function test_the_only_active_super_admin_cannot_be_deactivated(): void
    {
        $admin = $this->superAdmin();

        $this->expectException(LastActiveSuperAdminException::class);

        $this->guard->protect($admin, fn (User $user) => $user->update(['status' => User::STATUS_INACTIVE]));
    }

    public function test_the_only_active_super_admin_cannot_be_blocked(): void
    {
        $admin = $this->superAdmin();

        $this->expectException(LastActiveSuperAdminException::class);

        $this->guard->protect($admin, fn (User $user) => $user->update(['status' => User::STATUS_BLOCKED]));
    }

    public function test_the_only_active_super_admin_cannot_be_suspended(): void
    {
        $admin = $this->superAdmin();

        $this->expectException(LastActiveSuperAdminException::class);

        $this->guard->protect($admin, fn (User $user) => $user->update(['status' => User::STATUS_SUSPENDED]));
    }

    public function test_a_super_admin_can_be_deactivated_when_another_active_super_admin_remains(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();

        $this->guard->protect($admin, fn (User $user) => $user->update(['status' => User::STATUS_INACTIVE]));

        $this->assertSame(User::STATUS_INACTIVE, $admin->fresh()->status);
    }

    // ── protect(): deletion ──────────────────────────────────────────────────

    public function test_the_only_active_super_admin_cannot_be_soft_or_hard_deleted(): void
    {
        $admin = $this->superAdmin();

        $this->expectException(LastActiveSuperAdminException::class);

        $this->guard->protect($admin, fn (User $user) => $user->delete());

        $this->assertNotNull(User::query()->find($admin->id));
    }

    public function test_a_super_admin_can_be_deleted_when_another_active_super_admin_remains(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();

        $this->guard->protect($admin, fn (User $user) => $user->delete());

        $this->assertNull(User::query()->find($admin->id));
    }

    // ── Replacement / promotion sequencing ──────────────────────────────────

    public function test_promoting_and_activating_a_replacement_allows_the_original_account_to_be_changed_afterward(): void
    {
        $original = $this->superAdmin();
        $replacement = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        // Not yet safe — replacement isn't a super admin yet.
        try {
            $this->guard->protect($original, fn (User $user) => $user->update(['status' => User::STATUS_INACTIVE]));
            $this->fail('Expected LastActiveSuperAdminException before promotion.');
        } catch (LastActiveSuperAdminException) {
            // expected
        }

        $replacement->assignRole('super_admin');

        // Now safe.
        $this->guard->protect($original, fn (User $user) => $user->update(['status' => User::STATUS_INACTIVE]));

        $this->assertSame(User::STATUS_INACTIVE, $original->fresh()->status);
    }

    public function test_first_installation_bootstrap_creation_of_a_super_admin_remains_possible(): void
    {
        // Mirrors SuperAdminSeeder/DefaultRolesAndUsersSeeder: a bare
        // assignRole() call, exactly as bootstrap does it — the guard
        // only protects removal-side mutations, so this must never be
        // intercepted.
        $first = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $first->assignRole('super_admin');

        $this->assertTrue($this->guard->isActiveSuperAdmin($first->fresh()));
    }

    // ── Unrelated edits remain unrestricted ─────────────────────────────────

    public function test_unrelated_profile_edits_for_the_only_super_admin_remain_allowed(): void
    {
        $admin = $this->superAdmin();

        $this->guard->protect($admin, fn (User $user) => $user->update(['name' => 'Renamed Admin']));

        $this->assertSame('Renamed Admin', $admin->fresh()->name);
    }

    public function test_adding_roles_or_permissions_remains_allowed_for_the_only_super_admin(): void
    {
        $admin = $this->superAdmin();

        $this->guard->protect($admin, fn (User $user) => $user->assignRole('manager'));

        $this->assertTrue($admin->fresh()->hasRole('super_admin'));
        $this->assertTrue($admin->fresh()->hasRole('manager'));
    }

    // ── protectBatch() ───────────────────────────────────────────────────────

    public function test_unsafe_bulk_deactivation_is_rejected_atomically(): void
    {
        $a = $this->superAdmin();
        $b = $this->superAdmin();
        $c = $this->superAdmin();

        try {
            $this->guard->protectBatch([$a, $b, $c], fn (User $user) => $user->update(['status' => User::STATUS_INACTIVE]));
            $this->fail('Expected LastActiveSuperAdminException.');
        } catch (LastActiveSuperAdminException) {
            // expected
        }

        $this->assertSame(User::STATUS_ACTIVE, $a->fresh()->status, 'The whole batch must roll back, not just the last row.');
        $this->assertSame(User::STATUS_ACTIVE, $b->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $c->fresh()->status);
    }

    public function test_safe_bulk_deactivation_succeeds_without_reaching_zero(): void
    {
        $a = $this->superAdmin();
        $b = $this->superAdmin();
        $c = $this->superAdmin(); // left active — the survivor

        $this->guard->protectBatch([$a, $b], fn (User $user) => $user->update(['status' => User::STATUS_INACTIVE]));

        $this->assertSame(User::STATUS_INACTIVE, $a->fresh()->status);
        $this->assertSame(User::STATUS_INACTIVE, $b->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $c->fresh()->status);
    }

    // ── Canonical role protection ────────────────────────────────────────────

    public function test_the_canonical_super_admin_role_cannot_be_deleted(): void
    {
        $role = Role::where('name', 'super_admin')->firstOrFail();

        $this->expectException(CanonicalSuperAdminRoleProtectedException::class);

        $this->guard->assertRoleNotCanonical($role);
    }

    public function test_a_non_canonical_role_can_be_asserted_freely(): void
    {
        $role = Role::where('name', 'manager')->firstOrFail();

        $this->guard->assertRoleNotCanonical($role);
        $this->addToAssertionCount(1);
    }

    public function test_the_canonical_role_cannot_be_renamed_incompatibly(): void
    {
        $role = Role::where('name', 'super_admin')->firstOrFail();

        $this->expectException(CanonicalSuperAdminRoleProtectedException::class);

        $this->guard->assertCanonicalRoleNameUnchanged($role, 'super-admin-renamed');
    }

    public function test_the_canonical_role_may_be_saved_with_its_own_unchanged_name(): void
    {
        $role = Role::where('name', 'super_admin')->firstOrFail();

        $this->guard->assertCanonicalRoleNameUnchanged($role, 'super_admin');
        $this->addToAssertionCount(1);
    }

    public function test_a_non_canonical_role_can_be_renamed_freely(): void
    {
        $role = Role::where('name', 'manager')->firstOrFail();

        $this->guard->assertCanonicalRoleNameUnchanged($role, 'operations-manager');
        $this->addToAssertionCount(1);
    }
}
