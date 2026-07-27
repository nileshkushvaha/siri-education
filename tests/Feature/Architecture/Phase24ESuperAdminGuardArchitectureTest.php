<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * SRS-23-7: guards against a future first-party
 * regression that mutates admin-user status/roles or deletes a
 * User/Role record without routing through the central
 * SuperAdminGuardService. Does not (and cannot) protect against direct
 * database/DBA access or raw Spatie internals called from outside the
 * application — that boundary is documented, not enforced here.
 */
final class Phase24ESuperAdminGuardArchitectureTest extends TestCase
{
    /** @return array<int, string> */
    public static function protectedFiles(): array
    {
        return [
            app_path('Filament/Resources/Users/Pages/EditUser.php'),
            app_path('Filament/Resources/Users/Tables/UsersTable.php'),
            app_path('Filament/Resources/Roles/Tables/RolesTable.php'),
            app_path('Filament/Resources/Roles/Pages/EditRole.php'),
        ];
    }

    public function test_every_admin_user_and_role_mutation_surface_references_the_central_guard(): void
    {
        foreach (self::protectedFiles() as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            $this->assertStringContainsString(
                'SuperAdminGuardService',
                $contents,
                basename($file).' must route its destructive/role/status mutations through SuperAdminGuardService.',
            );
        }
    }

    public function test_the_guard_service_itself_is_the_only_place_that_defines_the_canonical_role_name(): void
    {
        $guard = file_get_contents(app_path('Services/Admin/SuperAdminGuardService.php'));
        $this->assertIsString($guard);
        $this->assertStringContainsString("SUPER_ADMIN_ROLE = 'super_admin'", $guard);

        // The protected Filament surfaces reference the constant, not a
        // second hardcoded 'super_admin' string of their own — a single
        // source of truth for "what is the canonical role."
        foreach (self::protectedFiles() as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString("'super_admin'", $contents, basename($file).' should reference SuperAdminGuardService::SUPER_ADMIN_ROLE, not a duplicated string literal.');
        }
    }

    public function test_no_duplicate_super_admin_guard_service_was_created(): void
    {
        $this->assertFileExists(app_path('Services/Admin/SuperAdminGuardService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Admin/AdminLifecycleGuardService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Admin/SuperAdminProtectionService.php'));
        $this->assertFileDoesNotExist(app_path('Services/SuperAdminGuardService.php'));
    }
}
