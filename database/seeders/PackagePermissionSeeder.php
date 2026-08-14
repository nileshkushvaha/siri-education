<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions for the Personalized Instructor Package Proposal domain.
 * PackageBenefitRule is a standard admin-managed master (mirrors
 * AcademicPermissionSeeder's CRUD-per-module shape). InstructorPackageProposal
 * is NOT a single CRUD-per-role set — the three roles get deliberately
 * disjoint action subsets: manager reviews/decides, instructor
 * creates/submits/cancels their own, student views/accepts their own.
 * super_admin needs no explicit grant here — every permission created
 * is auto-granted to it via the Permission::created observer in
 * AppServiceProvider.
 */
class PackagePermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const RULE_MANAGER_ACTIONS = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny', 'Restore', 'RestoreAny',
    ];

    private const RULE_SUPER_ONLY_ACTIONS = ['ForceDelete', 'ForceDeleteAny'];

    private const PROPOSAL_MANAGER_PERMISSIONS = [
        'ViewAny:InstructorPackageProposal',
        'View:InstructorPackageProposal',
        'Approve:InstructorPackageProposal',
        'Reject:InstructorPackageProposal',
        'OverridePrice:InstructorPackageProposal',
    ];

    private const PROPOSAL_INSTRUCTOR_PERMISSIONS = [
        'Create:InstructorPackageProposal',
        'View:InstructorPackageProposal',
        'Cancel:InstructorPackageProposal',
    ];

    private const PROPOSAL_STUDENT_PERMISSIONS = [
        'View:InstructorPackageProposal',
        'Accept:InstructorPackageProposal',
    ];

    /**
     * Phase 4A — entitlements are read-only for every role (created by
     * acceptance, mutated only by PackageEntitlementService), so there
     * are deliberately no Create/Update/Delete permissions at all.
     * `ViewAny` is the admin-wide listing; `View` is the row-level
     * ability the policy narrows to own-student / own-instructor.
     */
    private const ENTITLEMENT_MANAGER_PERMISSIONS = [
        'ViewAny:StudentPackageEntitlement',
        'View:StudentPackageEntitlement',
    ];

    private const ENTITLEMENT_PARTICIPANT_PERMISSIONS = [
        'View:StudentPackageEntitlement',
    ];

    /**
     * Phase 4B.2 — purchases are financial records. Nobody, at any
     * level, gets Create/Update/Delete: a purchase is written by
     * acceptance and settled by a verified webhook, both inside trusted
     * services. `Pay` is the student's own checkout capability (it also
     * covers cancelling their own open attempt) and is deliberately not
     * granted to instructors or managers — no role may pay on a
     * student's behalf.
     */
    private const PURCHASE_MANAGER_PERMISSIONS = [
        'ViewAny:StudentPackagePurchase',
        'View:StudentPackagePurchase',
    ];

    private const PURCHASE_INSTRUCTOR_PERMISSIONS = [
        'View:StudentPackagePurchase',
    ];

    private const PURCHASE_STUDENT_PERMISSIONS = [
        'View:StudentPackagePurchase',
        'Pay:StudentPackagePurchase',
    ];

    public function run(): void
    {
        $ruleManagerPermissions = [];

        foreach (self::RULE_MANAGER_ACTIONS as $action) {
            $ruleManagerPermissions[] = "{$action}:PackageBenefitRule";
        }

        foreach (self::RULE_SUPER_ONLY_ACTIONS as $action) {
            Permission::firstOrCreate(['name' => "{$action}:PackageBenefitRule", 'guard_name' => 'web']);
        }

        $allPackagePermissions = array_unique([
            ...self::PROPOSAL_MANAGER_PERMISSIONS,
            ...self::PROPOSAL_INSTRUCTOR_PERMISSIONS,
            ...self::PROPOSAL_STUDENT_PERMISSIONS,
            ...self::ENTITLEMENT_MANAGER_PERMISSIONS,
            ...self::ENTITLEMENT_PARTICIPANT_PERMISSIONS,
            ...self::PURCHASE_MANAGER_PERMISSIONS,
            ...self::PURCHASE_INSTRUCTOR_PERMISSIONS,
            ...self::PURCHASE_STUDENT_PERMISSIONS,
        ]);

        foreach ([...$ruleManagerPermissions, ...$allPackagePermissions] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Must run before any givePermissionTo() call below — Spatie
        // caches the full permission list, and a permission created
        // moments ago in this same process is invisible to
        // hasPermissionTo()/givePermissionTo() until the cache is
        // dropped. Forgetting again at the end (belt-and-braces) covers
        // any other process that already warmed the cache earlier.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo([...$ruleManagerPermissions, ...self::PROPOSAL_MANAGER_PERMISSIONS, ...self::ENTITLEMENT_MANAGER_PERMISSIONS, ...self::PURCHASE_MANAGER_PERMISSIONS]);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web'])
            ->givePermissionTo([...self::PROPOSAL_INSTRUCTOR_PERMISSIONS, ...self::ENTITLEMENT_PARTICIPANT_PERMISSIONS, ...self::PURCHASE_INSTRUCTOR_PERMISSIONS]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web'])
            ->givePermissionTo([...self::PROPOSAL_STUDENT_PERMISSIONS, ...self::ENTITLEMENT_PARTICIPANT_PERMISSIONS, ...self::PURCHASE_STUDENT_PERMISSIONS]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
