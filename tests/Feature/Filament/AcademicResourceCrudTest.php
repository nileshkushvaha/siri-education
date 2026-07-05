<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Academic\Pages\CreateAcademicCategory;
use App\Filament\Resources\Academic\Pages\EditAcademicCategory;
use App\Models\AcademicCategory;
use App\Models\User;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AcademicResourceCrudTest extends TestCase
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

    public function test_manager_can_list_academic_categories(): void
    {
        AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);

        $this->actingAs($this->manager)
            ->get(route('filament.admin.resources.academic.academic-categories.index'))
            ->assertOk()
            ->assertSee('Mathematics');
    }

    public function test_unauthorized_user_cannot_list_academic_categories(): void
    {
        $this->actingAs($this->unauthorized)
            ->get(route('filament.admin.resources.academic.academic-categories.index'))
            ->assertForbidden();
    }

    public function test_manager_can_create_academic_category(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(CreateAcademicCategory::class)
            ->fillForm([
                'name' => 'Computer Science',
                'slug' => 'computer-science',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('academic_categories', ['slug' => 'computer-science']);
    }

    public function test_manager_can_edit_academic_category(): void
    {
        $category = AcademicCategory::create(['name' => 'Arts', 'slug' => 'arts']);

        $this->actingAs($this->manager);

        Livewire::test(EditAcademicCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['name' => 'Arts and Humanities'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Arts and Humanities', $category->fresh()->name);
    }
}
