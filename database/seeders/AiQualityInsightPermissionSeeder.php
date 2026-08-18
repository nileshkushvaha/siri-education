<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin AI Quality Intelligence permissions (P1).
 *
 * Manager-only, with no instructor or student grant of any kind —
 * an insight is an internal prompt for human attention, not feedback
 * delivered to its subject. Generate is separate from ViewAny because
 * it spends AI budget; Review is separate from both because marking an
 * insight reviewed is a statement that a person took responsibility for
 * it.
 *
 * Idempotent — required after deploy: AiQualityInsightPolicy denies
 * unknown permissions, so without this only super_admin can open the
 * page.
 */
class AiQualityInsightPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:AiQualityInsight',
        'View:AiQualityInsight',
        'Generate:AiQualityInsight',
        'Review:AiQualityInsight',
    ];

    public function run(): void
    {
        foreach (self::MANAGER_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
