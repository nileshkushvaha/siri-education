<?php

declare(strict_types=1);

namespace Tests\Feature\PromotionalCredits\Concerns;

use App\Models\Currency;
use App\Models\PromotionalCreditCampaign;
use App\Models\User;
use App\Settings\FeatureSettings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait CreatesPromotionalCreditFixtures
{
    protected function ensurePromotionalCreditRoles(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        Currency::query()->firstOrCreate(
            ['code' => 'INR'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'minor_units' => 2, 'status' => 'active'],
        );

        $features = app(FeatureSettings::class);
        $features->promotional_credit_enabled = true;
        // Phase 34 (GAP-029): promotional credits now compose with the
        // Wallet feature as a dependency (CountryFeature::PromotionalCredits
        // ->dependencies() === [Wallet]) — wallet_enabled defaults false,
        // so every fixture built from this trait must also enable it.
        $features->wallet_enabled = true;
        $features->save();
    }

    protected function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    protected function admin(array $permissions = []): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        if ($permissions !== []) {
            $admin->givePermissionTo($permissions);
        }

        return $admin;
    }

    protected function fullAdmin(): User
    {
        return $this->admin([
            'ViewPromotionalCreditCampaigns',
            'ManagePromotionalCreditCampaigns',
            'IssuePromotionalCredit',
            'ViewPromotionalCreditIssuances',
        ]);
    }

    protected function activeCampaign(array $overrides = []): PromotionalCreditCampaign
    {
        return PromotionalCreditCampaign::factory()->active()->create($overrides);
    }
}
