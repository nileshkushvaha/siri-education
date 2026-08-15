<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIONS = [
        'ViewAny',
        'View',
        'Create',
        'Update',
        'Delete',
        'DeleteAny',
        'Restore',
        'RestoreAny',
        'Replicate',
        'Reorder',
        'ForceDelete',
        'ForceDeleteAny',
    ];

    public function up(): void
    {
        $this->removePermissions();

        if (Schema::hasColumn('user_profiles', 'instructor_skill_level_ids')) {
            Schema::table('user_profiles', function (Blueprint $table): void {
                $table->dropColumn('instructor_skill_level_ids');
            });
        }

        Schema::dropIfExists('skill_levels');
    }

    public function down(): void
    {
        if (! Schema::hasTable('skill_levels')) {
            Schema::create('skill_levels', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name', 100);
                $table->string('slug', 100)->unique();
                $table->unsignedSmallInteger('display_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index('is_active');
                $table->index('display_order');
            });
        }

        if (! Schema::hasColumn('user_profiles', 'instructor_skill_level_ids')) {
            Schema::table('user_profiles', function (Blueprint $table): void {
                $table->json('instructor_skill_level_ids')->nullable()->after('instructor_academic_level_ids');
            });
        }

        $this->restorePermissions();
    }

    private function removePermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissionNames())
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        if (Schema::hasTable('model_has_permissions')) {
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    private function restorePermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => [
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $this->permissionNames(),
        ));

        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $managerId = DB::table('roles')->where('name', 'manager')->where('guard_name', 'web')->value('id');

        if ($managerId === null) {
            return;
        }

        $managerPermissionIds = DB::table('permissions')
            ->whereIn('name', array_map(fn (string $action): string => "{$action}:SkillLevel", array_slice(self::ACTIONS, 0, 10)))
            ->pluck('id');

        DB::table('role_has_permissions')->insertOrIgnore(
            $managerPermissionIds->map(fn ($permissionId): array => [
                'permission_id' => (int) $permissionId,
                'role_id' => $managerId,
            ])->all(),
        );
    }

    /** @return list<string> */
    private function permissionNames(): array
    {
        return array_map(fn (string $action): string => "{$action}:SkillLevel", self::ACTIONS);
    }
};
