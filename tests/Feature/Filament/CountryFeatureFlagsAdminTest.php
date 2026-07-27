<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Countries\Pages\EditCountry;
use App\Models\Activity;
use App\Models\Country;
use App\Models\User;
use Database\Seeders\LocalizationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Country admin form's Feature Availability
 * section: authorization reuses the existing Country permission set,
 * a save is audited the same way any other Country field change is
 * (Activitylog, requirement #6), and an admin-form dependency
 * violation surfaces as a validation error rather than a raw 500.
 */
class CountryFeatureFlagsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $unauthorized;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LocalizationPermissionSeeder::class);

        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole($managerRole);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->unauthorized = User::factory()->create(['status' => 'active']);
        $this->unauthorized->assignRole('student');
    }

    public function test_manager_can_disable_a_feature_for_a_country_and_it_is_audited(): void
    {
        $country = Country::factory()->create();

        $this->actingAs($this->manager);

        Livewire::test(EditCountry::class, ['record' => $country->getRouteKey()])
            ->fillForm(['feature_flags.wallet' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($country->fresh()->feature_flags['wallet']);

        $this->assertTrue(
            Activity::query()
                ->where('log_name', 'countries')
                ->where('subject_type', Country::class)
                ->where('subject_id', $country->id)
                ->exists(),
            'A feature-flag change must be audited exactly like any other Country field change.'
        );
    }

    public function test_unauthorized_user_cannot_open_the_country_edit_form(): void
    {
        $country = Country::factory()->create();

        $this->actingAs($this->unauthorized)
            ->get(route('filament.admin.resources.countries.edit', $country))
            ->assertForbidden();
    }

    public function test_leaving_a_dependent_feature_enabled_while_disabling_its_dependency_is_normalized_to_disabled(): void
    {
        $country = Country::factory()->create();

        $this->actingAs($this->manager);

        Livewire::test(EditCountry::class, ['record' => $country->getRouteKey()])
            ->fillForm(['feature_flags.wallet' => false, 'feature_flags.wallet_recharge' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $flags = $country->fresh()->feature_flags;
        $this->assertFalse($flags['wallet']);
        $this->assertFalse($flags['wallet_recharge'], 'Wallet Recharge must be normalized to disabled once its dependency (Wallet) is off.');
    }
}
