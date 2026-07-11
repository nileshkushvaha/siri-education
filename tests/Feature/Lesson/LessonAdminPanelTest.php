<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LessonAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_lessons_resource_renders_for_super_admin(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        Lesson::factory()->count(2)->create();

        $this->actingAs($admin)->get('/admin/lessons')->assertOk();
    }

    public function test_lessons_resource_renders_for_seeded_manager(): void
    {
        $this->seed(LessonPermissionSeeder::class);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        Lesson::factory()->create();

        $this->actingAs($manager)->get('/admin/lessons')->assertOk();
    }

    public function test_lessons_resource_denies_users_without_permissions(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)->get('/admin/lessons')->assertForbidden();
    }
}
