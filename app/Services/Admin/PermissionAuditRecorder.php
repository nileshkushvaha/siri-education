<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use App\Services\AuditTrailService;
use Spatie\Permission\Models\Permission;

/**
 * The Permission-side sibling
 * of RoleAuditRecorder. Kept as a separate class rather than folded into
 * RoleAuditRecorder — the two domains' safe property shapes genuinely
 * differ (role diffs vs. permission rename/affected-role-count), and
 * merging them would blur which mutation is which in the properties
 * contract. Every method still ends in exactly one
 * AuditTrailService::logUser() call — not a second logger.
 */
final class PermissionAuditRecorder
{
    public function __construct(private readonly AuditTrailService $audit) {}

    public function recordCreated(User $actor, Permission $permission, bool $autoGrantedToSuperAdmin, string $source): void
    {
        $this->audit->logUser(
            $actor,
            'permissions',
            'created',
            "Permission \"{$permission->name}\" created",
            $permission,
            [
                'permission_id' => $permission->getKey(),
                'permission_name' => $permission->name,
                'guard_name' => $permission->guard_name,
                'auto_granted_to_super_admin' => $autoGrantedToSuperAdmin,
                'source' => $source,
            ],
        );
    }

    /**
     * Writes nothing if neither the name nor the guard changed — a true
     * no-op save must never produce an audit event. Spatie resolves role
     * assignments by the permission's ID (model_has_permissions /
     * role_has_permissions reference permission_id, never the name
     * string), so a rename never touches existing pivot rows — nothing
     * extra is needed here to "preserve" them.
     */
    public function recordUpdated(User $actor, Permission $permission, ?string $previousName, ?string $previousGuard, string $source): void
    {
        $nameChanged = $previousName !== null && $previousName !== $permission->name;
        $guardChanged = $previousGuard !== null && $previousGuard !== $permission->guard_name;

        if (! $nameChanged && ! $guardChanged) {
            return;
        }

        $properties = [
            'permission_id' => $permission->getKey(),
            'permission_name' => $permission->name,
            'guard_name' => $permission->guard_name,
            'source' => $source,
        ];

        if ($nameChanged) {
            $properties['previous_name'] = $previousName;
            $properties['new_name'] = $permission->name;
        }

        if ($guardChanged) {
            $properties['previous_guard_name'] = $previousGuard;
            $properties['new_guard_name'] = $permission->guard_name;
        }

        $this->audit->logUser(
            $actor,
            'permissions',
            'updated',
            "Permission \"{$permission->name}\" updated",
            $permission,
            $properties,
        );
    }

    /**
     * Must be called BEFORE the permission is deleted — performedOn()
     * needs the record to still exist, and $affectedRoleNames must be
     * captured before the pivot rows disappear with it.
     *
     * @param  list<string>  $affectedRoleNames  Names of roles that had this permission, captured before deletion.
     */
    public function recordDeleted(User $actor, Permission $permission, array $affectedRoleNames, string $source): void
    {
        $this->audit->logUser(
            $actor,
            'permissions',
            'deleted',
            "Permission \"{$permission->name}\" deleted",
            $permission,
            [
                'permission_id' => $permission->getKey(),
                'permission_name' => $permission->name,
                'guard_name' => $permission->guard_name,
                'affected_role_count' => count($affectedRoleNames),
                'affected_role_names' => $this->sorted($affectedRoleNames),
                'source' => $source,
            ],
        );
    }

    /**
     * @param  array<int, string>  $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        $names = array_values($names);
        sort($names);

        return $names;
    }
}
