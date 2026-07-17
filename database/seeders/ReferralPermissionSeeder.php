<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 19B referral permissions — the deliberate minimum. Idempotent.
 *
 * `ViewReferralAttributions` gates read-only inspection of referral
 * relationships; `DisableReferralCodes` gates the abuse-response code
 * disable in ReferralCodeService::disable(). Managers get read-only by
 * default; disabling a student's code is deliberately NOT granted to
 * any role here — a super_admin must consciously grant it (and always
 * has it via Gate::before()). Phase 19C added the campaign pair:
 * `ViewReferralCampaigns` / `ManageReferralCampaigns` gate the Filament
 * campaign resource and every ReferralCampaignService mutation. Reward
 * review and attribution correction arrive in Phase 19D/19E with their
 * own permissions.
 *
 * Students need no permission for their own Refer a Friend page —
 * role/ownership plus FeatureSettings::$referral_enabled gate it.
 */
class ReferralPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewReferralAttributions',
        // Phase 19C — campaign administration (ReferralCampaignPolicy +
        // ReferralCampaignService both authorize against these).
        'ViewReferralCampaigns',
        'ManageReferralCampaigns',
    ];

    private const array UNGRANTED_PERMISSIONS = [
        'DisableReferralCodes',
    ];

    public function run(): void
    {
        foreach ([...self::MANAGER_PERMISSIONS, ...self::UNGRANTED_PERMISSIONS] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
