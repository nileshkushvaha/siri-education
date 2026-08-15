<?php

declare(strict_types=1);

namespace Tests\Unit\Academic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RemoveSkillLevelsMigrationTest extends TestCase
{
    public function test_migration_removes_and_restores_skill_level_schema_and_permissions(): void
    {
        $originalConnection = DB::getDefaultConnection();

        config()->set('database.connections.skill_level_retirement', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::setDefaultConnection('skill_level_retirement');

        try {
            $this->createPrerequisites();

            $migration = require database_path('migrations/2026_10_31_100000_remove_skill_levels.php');
            $migration->up();

            $this->assertFalse(Schema::hasTable('skill_levels'));
            $this->assertFalse(Schema::hasColumn('user_profiles', 'instructor_skill_level_ids'));
            $this->assertSame(0, DB::table('permissions')->where('name', 'ViewAny:SkillLevel')->count());
            $this->assertSame(0, DB::table('role_has_permissions')->count());

            $migration->down();

            $this->assertTrue(Schema::hasTable('skill_levels'));
            $this->assertTrue(Schema::hasColumn('user_profiles', 'instructor_skill_level_ids'));
            $this->assertSame(12, DB::table('permissions')->where('name', 'like', '%:SkillLevel')->count());
            $this->assertSame(10, DB::table('role_has_permissions')->count());
        } finally {
            DB::disconnect('skill_level_retirement');
            DB::setDefaultConnection($originalConnection);
        }
    }

    private function createPrerequisites(): void
    {
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->json('instructor_academic_level_ids')->nullable();
            $table->json('instructor_skill_level_ids')->nullable();
            $table->json('instructor_teaching_language_ids')->nullable();
        });

        Schema::create('skill_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'ViewAny:SkillLevel',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $managerId = DB::table('roles')->insertGetId([
            'name' => 'manager',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_has_permissions')->insert([
            'permission_id' => $permissionId,
            'role_id' => $managerId,
        ]);
    }
}
