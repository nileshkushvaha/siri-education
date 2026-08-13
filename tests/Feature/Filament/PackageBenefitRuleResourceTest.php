<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\PackageBenefitRules\PackageBenefitRuleResource;
use App\Filament\Resources\PackageBenefitRules\Pages\CreatePackageBenefitRule;
use App\Models\User;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PackageBenefitRuleResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->manager->assignRole('manager');
    }

    public function test_manager_can_create_a_package_rule(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(CreatePackageBenefitRule::class)
            ->fillForm([
                'name' => '14 paid + 1 bonus',
                'paid_quantity' => 14,
                'bonus_quantity' => 1,
                'total_quantity' => 15,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('package_benefit_rules', [
            'name' => '14 paid + 1 bonus',
            'paid_quantity' => 14,
            'total_quantity' => 15,
        ]);
    }

    public function test_instructor_cannot_view_the_resource(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $this->actingAs($instructor);

        $this->assertFalse(PackageBenefitRuleResource::canViewAny());
    }

    public function test_manager_can_view_the_resource(): void
    {
        $this->actingAs($this->manager);

        $this->assertTrue(PackageBenefitRuleResource::canViewAny());
    }

    /**
     * Phase 3.2 — the model/table keep their internal
     * PackageBenefitRule naming, but nothing an admin reads may say
     * "rule". Asserted so a future label edit can't quietly reintroduce
     * the internal jargon.
     */
    public function test_resource_labels_use_package_offer_terminology(): void
    {
        $this->assertSame('Package Offer', PackageBenefitRuleResource::getModelLabel());
        $this->assertSame('Package Offers', PackageBenefitRuleResource::getPluralModelLabel());

        foreach ([PackageBenefitRuleResource::getModelLabel(), PackageBenefitRuleResource::getPluralModelLabel()] as $label) {
            $this->assertStringNotContainsStringIgnoringCase('rule', $label);
        }
    }

    public function test_create_page_renders_offer_terminology_and_lesson_field_labels(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(CreatePackageBenefitRule::class)
            ->assertSee('Package Offer')
            ->assertSee('Paid Lessons')
            ->assertSee('Bonus Lessons')
            ->assertSee('Total Lessons')
            ->assertDontSee('Paid Quantity')
            ->assertDontSee('Bonus Quantity')
            ->assertDontSee('Total Quantity');
    }
}
