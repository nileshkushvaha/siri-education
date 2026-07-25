<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * GAP-041 promotional-credit permissions, matching the Referral
 * module's plain-name convention (ViewReferralCampaigns/
 * ManageReferralCampaigns) rather than the Shield-style `Verb:Model`
 * names used elsewhere — promotional credits are a sibling of referral
 * campaigns, not the Support Case/Messaging domains. Idempotent.
 *
 * IssuePromotionalCredit deliberately does NOT require Manage:Wallet —
 * PromotionalCreditService resolves the student's own wallet with the
 * student as actor (mirroring ReferralRewardService), so issuing a
 * promotional credit never needs the broader, deliberately-ungranted
 * wallet freeze/unfreeze/reversal power.
 */
class PromotionalCreditPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewPromotionalCreditCampaigns',
        'ManagePromotionalCreditCampaigns',
        'IssuePromotionalCredit',
        'ViewPromotionalCreditIssuances',
    ];

    public function run(): void
    {
        foreach (self::MANAGER_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
