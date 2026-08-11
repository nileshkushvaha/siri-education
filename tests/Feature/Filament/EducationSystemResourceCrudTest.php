<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Curriculum\Services\EducationSystemService;
use App\Models\Country;
use App\Models\User;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EducationSystemResourceCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $unauthorized;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicPermissionSeeder::class);

        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole($managerRole);

        $this->unauthorized = User::factory()->create(['status' => 'active']);
    }

    public function test_manager_can_list_education_systems(): void
    {
        app(EducationSystemService::class)->createEducationSystem($this->manager, [
            'name' => 'CBSE',
            'slug' => 'cbse',
        ]);

        $this->actingAs($this->manager)
            ->get(route('filament.admin.resources.academic.education-systems.index'))
            ->assertOk()
            ->assertSee('CBSE');
    }

    public function test_unauthorized_user_cannot_list_education_systems(): void
    {
        $this->actingAs($this->unauthorized)
            ->get(route('filament.admin.resources.academic.education-systems.index'))
            ->assertForbidden();
    }

    public function test_manager_can_open_create_page(): void
    {
        $this->actingAs($this->manager)
            ->get(route('filament.admin.resources.academic.education-systems.create'))
            ->assertOk();
    }

    public function test_manager_can_open_edit_page_with_mapping_relation_managers(): void
    {
        $system = app(EducationSystemService::class)->createEducationSystem($this->manager, [
            'name' => 'IB',
            'slug' => 'ib',
        ]);

        Country::factory()->create(['name' => 'Test Country']);

        $this->actingAs($this->manager)
            ->get(route('filament.admin.resources.academic.education-systems.edit', $system))
            ->assertOk()
            ->assertSee('Countries')
            ->assertSee('Academic Levels')
            ->assertSee('Curricula');
    }
}
