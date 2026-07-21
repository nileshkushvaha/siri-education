<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\InstructorStatus;
use App\Exceptions\LastActiveSuperAdminException;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use App\Filament\Widgets\Quality\AlertQueueWidget;
use App\Models\User;
use App\Services\Admin\SuperAdminGuardService;
use App\Services\Auth\AccountProtectionService;
use App\Services\Auth\LoginSecurityService;
use App\Settings\AccountProtectionSettings;
use App\Settings\LoginSecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24T — GAP: closes the class of RoleDoesNotExist crashes on
 * read-only role-filtered queries beyond InstructorOnboardingResource
 * (Phase 24S.1 fixed only the nav badge). Every site below used
 * Spatie's throwing `role()` scope on a read path; each is now proven
 * to behave identically when the role exists and to degrade to an
 * empty/no-op result — never an exception — when it doesn't.
 */
class RoleQueryReadPathResilienceTest extends TestCase
{
    use RefreshDatabase;

    // ── User::scopeWhereHasRoleNamed() — the shared convention itself ──────

    public function test_where_has_role_named_returns_matching_users_when_the_role_exists(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $ids = User::query()->whereHasRoleNamed('manager')->pluck('id');

        $this->assertTrue($ids->contains($manager->id));
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_where_has_role_named_returns_empty_and_does_not_throw_when_the_role_row_is_absent(): void
    {
        $this->assertFalse(Role::where('name', 'manager')->where('guard_name', 'web')->exists());

        $result = User::query()->whereHasRoleNamed('manager')->get();

        $this->assertCount(0, $result);
    }

    public function test_where_has_role_named_never_creates_the_missing_role(): void
    {
        User::query()->whereHasRoleNamed('manager')->get();

        $this->assertFalse(Role::where('name', 'manager')->where('guard_name', 'web')->exists());
    }

    // ── InstructorOnboardingResource::getEloquentQuery() ────────────────────

    public function test_instructor_onboarding_list_query_returns_the_same_instructors_when_the_role_exists(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $ids = InstructorOnboardingResource::getEloquentQuery()->pluck('users.id');

        $this->assertTrue($ids->contains($instructor->id));
    }

    public function test_instructor_onboarding_list_query_returns_empty_and_does_not_throw_when_the_role_is_absent(): void
    {
        $this->assertFalse(Role::where('name', 'instructor')->where('guard_name', 'web')->exists());

        $result = InstructorOnboardingResource::getEloquentQuery()->get();

        $this->assertCount(0, $result);
    }

    // ── AlertQueueWidget's manager assignee options ──────────────────────────

    private function managerOptions(): array
    {
        return (new ReflectionMethod(AlertQueueWidget::class, 'managerOptions'))->invoke(null);
    }

    public function test_manager_options_include_managers_when_the_role_exists(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Manager One']);
        $manager->assignRole('manager');
        $nonManager = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $options = $this->managerOptions();

        $this->assertArrayHasKey($manager->id, $options);
        $this->assertArrayNotHasKey($nonManager->id, $options);
    }

    public function test_manager_options_are_empty_and_do_not_throw_when_the_manager_role_is_absent(): void
    {
        $this->assertFalse(Role::where('name', 'manager')->where('guard_name', 'web')->exists());

        $this->assertSame([], $this->managerOptions());
    }

    public function test_reading_manager_options_does_not_create_the_manager_role(): void
    {
        $this->managerOptions();

        $this->assertFalse(Role::where('name', 'manager')->where('guard_name', 'web')->exists());
    }

    // ── LoginSecurityService / AccountProtectionService admin notification ──

    public function test_login_security_admin_notification_is_skipped_not_fatal_when_super_admin_role_is_absent(): void
    {
        $this->assertFalse(Role::where('name', 'super_admin')->where('guard_name', 'web')->exists());

        app(AccountProtectionSettings::class)->disable_after_failed_attempts = true;
        app(AccountProtectionSettings::class)->auto_unlock_after = 30;
        app(AccountProtectionSettings::class)->save();
        app(LoginSecuritySettings::class)->max_failed_attempts = 1;
        app(LoginSecuritySettings::class)->save();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'failed_login_count' => 0]);

        // Must not throw RoleDoesNotExist while locking the account.
        app(LoginSecurityService::class)->recordFailedAttempt($user, '127.0.0.1');

        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_account_protection_manual_lock_admin_notification_is_skipped_not_fatal_when_super_admin_role_is_absent(): void
    {
        $this->assertFalse(Role::where('name', 'super_admin')->where('guard_name', 'web')->exists());

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->assignRole('manager');
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        app(AccountProtectionSettings::class)->notify_admin = true;
        app(AccountProtectionSettings::class)->save();

        // Must not throw RoleDoesNotExist while manually locking the account.
        app(AccountProtectionService::class)->manualLock($user, $actor, 'Policy violation.');

        $this->assertTrue($user->fresh()->isLocked());
    }

    // ── SuperAdminGuardService: deliberately unchanged (Category D) ─────────

    /**
     * SuperAdminGuardService's role() calls were deliberately left
     * unchanged (see the Phase 24T report) — this is the platform's
     * most safety-critical invariant, and the super_admin role is
     * protected from deletion/rename by this very service, so it is
     * always expected to exist. This test proves the invariant itself
     * is untouched by this phase's changes: the last active Super Admin
     * still cannot be demoted.
     */
    public function test_super_admin_guard_still_blocks_demoting_the_last_active_super_admin(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $lastAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $lastAdmin->assignRole($role);

        $this->expectException(LastActiveSuperAdminException::class);

        app(SuperAdminGuardService::class)->protect($lastAdmin, function (User $user): void {
            $user->removeRole('super_admin');
        });

        $this->assertTrue($lastAdmin->fresh()->hasRole('super_admin'));
    }

    public function test_super_admin_guard_allows_demoting_a_super_admin_when_another_remains_active(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin1 = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin1->assignRole($role);
        $admin2 = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin2->assignRole($role);

        app(SuperAdminGuardService::class)->protect($admin1, function (User $user): void {
            $user->removeRole('super_admin');
        });

        $this->assertFalse($admin1->fresh()->hasRole('super_admin'));
    }

    // ── Architecture guard: no throwing Spatie string-role scope on a governed read path ──

    /**
     * @return list<string> production read-only surfaces this phase
     *                      converted onto whereHasRoleNamed() — must
     *                      never regress back to Spatie's throwing
     *                      role() scope.
     */
    public static function governedReadOnlyFiles(): array
    {
        $base = base_path();

        return [
            $base.'/app/Filament/Resources/InstructorOnboarding/InstructorOnboardingResource.php',
            $base.'/app/Filament/Widgets/Quality/AlertQueueWidget.php',
            $base.'/app/Services/Auth/LoginSecurityService.php',
            $base.'/app/Services/Auth/AccountProtectionService.php',
        ];
    }

    public function test_governed_read_only_files_never_use_the_throwing_role_scope(): void
    {
        foreach (self::governedReadOnlyFiles() as $file) {
            $this->assertFileExists($file);
            $source = file_get_contents($file);

            $this->assertStringNotContainsString('->role(', $source, basename($file).' must use whereHasRoleNamed(), not Spatie\'s throwing role() scope, on this read-only surface.');
            $this->assertStringNotContainsString('::role(', $source, basename($file).' must use whereHasRoleNamed(), not Spatie\'s throwing role() scope, on this read-only surface.');
        }
    }

    /**
     * The shared scope itself must never call Role::findByName()/findById()
     * (the throwing resolution Spatie's own role() scope performs) or
     * create anything — a pure whereHas() read.
     */
    public function test_the_shared_scope_never_resolves_or_creates_a_role(): void
    {
        $source = file_get_contents(base_path('app/Models/User.php'));

        $this->assertMatchesRegularExpression('/function scopeWhereHasRoleNamed\(.*?\n\s*\{.*?\n\s*\}/s', $source);

        // Extract just the scope method body to avoid matching unrelated code elsewhere in the file.
        preg_match('/function scopeWhereHasRoleNamed\(.*?\n\s*\{(.*?)\n\s*\}/s', $source, $matches);
        $body = $matches[1] ?? '';

        $this->assertNotSame('', $body, 'scopeWhereHasRoleNamed() body could not be located.');
        $this->assertStringNotContainsString('findByName', $body);
        $this->assertStringNotContainsString('findById', $body);
        $this->assertStringNotContainsString('firstOrCreate', $body);
        $this->assertStringNotContainsString('::create(', $body);
        $this->assertStringContainsString('whereHas(', $body);
    }

    /**
     * Deliberately NOT governed — legitimate mutation/authorization/
     * bootstrap usage that must keep using Spatie's own role-resolving
     * behavior (see the Phase 24T report for the reasoning behind each):
     * SuperAdminGuardService (safety-critical invariant, mutation-guard
     * path), InstructorOnboardingResource::pendingReviewQuery() already
     * uses whereHasRoleNamed() (converted in 24S.1/24T, covered above by
     * the same file being asserted role()-free), and
     * ReconcileStudentLifecycleStatus (operator CLI tool whose read
     * report shares a query with a governed mutation). This test
     * documents that the guard above is deliberately narrow, not a
     * blanket ban.
     */
    public function test_super_admin_guard_service_deliberately_still_uses_the_strict_role_scope(): void
    {
        $source = file_get_contents(base_path('app/Services/Admin/SuperAdminGuardService.php'));

        $this->assertStringContainsString('User::role(', $source, 'SuperAdminGuardService is a deliberate Category D exception — see the Phase 24T report.');
    }
}
