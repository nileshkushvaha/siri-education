<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use App\Services\AuditTrailService;
use Spatie\Permission\Models\Role;

/**
 * Phase 24Q — GAP-011/SRS-23-6: the single place every Role/Permission
 * mutation surface (CreateRole, EditRole, RolesTable row/bulk/replicate
 * actions) records its audit entry through. Not a second logger — every
 * method here ends in exactly one AuditTrailService::logUser() call; this
 * class only owns the snapshot/diff/no-op shape shared across those
 * surfaces so it isn't duplicated per Filament page/action closure.
 *
 * Permission-name arrays are always returned sorted (deterministic
 * ordering — two runs over the same logical change must produce
 * byte-identical properties for consumers doing exact-match comparisons).
 */
final class RoleAuditRecorder
{
    public function __construct(private readonly AuditTrailService $audit) {}

    /**
     * @param  list<string>  $grantedPermissions
     */
    public function recordCreated(User $actor, Role $role, array $grantedPermissions, string $source): void
    {
        $this->audit->logUser(
            $actor,
            'roles',
            'created',
            "Role \"{$role->name}\" created",
            $role,
            [
                'role_id' => $role->getKey(),
                'role_name' => $role->name,
                'permissions_added' => $this->sorted($grantedPermissions),
                'permissions_removed' => [],
                'permission_count' => count($grantedPermissions),
                'source' => $source,
            ],
        );
    }

    /**
     * Diffs $beforePermissions against the role's CURRENT (already-persisted)
     * permissions, and compares $previousName against the role's CURRENT
     * name. $otherFieldsChanged covers everything else the caller persisted
     * (description/status/remarks) that this method doesn't itemize —
     * still counts toward "was anything actually committed", so a
     * description-only save is a real audited change, not a no-op. Writes
     * nothing only when NONE of these differ — a true no-op save must
     * never produce an audit event.
     *
     * @param  list<string>  $beforePermissions  Permission names the role had before this mutation.
     */
    public function recordUpdated(User $actor, Role $role, ?string $previousName, array $beforePermissions, string $source, bool $otherFieldsChanged = false): void
    {
        $afterPermissions = $role->permissions()->pluck('name')->all();

        $added = $this->sorted(array_values(array_diff($afterPermissions, $beforePermissions)));
        $removed = $this->sorted(array_values(array_diff($beforePermissions, $afterPermissions)));
        $nameChanged = $previousName !== null && $previousName !== $role->name;

        if (! $nameChanged && ! $otherFieldsChanged && $added === [] && $removed === []) {
            return;
        }

        $properties = [
            'role_id' => $role->getKey(),
            'role_name' => $role->name,
            'permissions_added' => $added,
            'permissions_removed' => $removed,
            'permission_count' => count($afterPermissions),
            'source' => $source,
        ];

        if ($nameChanged) {
            $properties['previous_name'] = $previousName;
            $properties['new_name'] = $role->name;
        }

        $this->audit->logUser(
            $actor,
            'roles',
            'updated',
            "Role \"{$role->name}\" updated",
            $role,
            $properties,
        );
    }

    public function recordDeleted(User $actor, Role $role, string $source): void
    {
        // Must be called BEFORE the role is deleted — performedOn() needs
        // the record to still exist, and the permission count must reflect
        // what was actually removed, not an already-empty relation.
        $this->audit->logUser(
            $actor,
            'roles',
            'deleted',
            "Role \"{$role->name}\" deleted",
            $role,
            [
                'role_id' => $role->getKey(),
                'role_name' => $role->name,
                'permission_count' => $role->permissions()->count(),
                'source' => $source,
            ],
        );
    }

    /**
     * @param  list<string>  $copiedPermissions
     */
    public function recordReplicated(User $actor, Role $original, Role $replica, array $copiedPermissions, string $source): void
    {
        $this->audit->logUser(
            $actor,
            'roles',
            'replicated',
            "Role \"{$original->name}\" duplicated as \"{$replica->name}\"",
            $replica,
            [
                'role_id' => $replica->getKey(),
                'role_name' => $replica->name,
                'source_role_id' => $original->getKey(),
                'source_role_name' => $original->name,
                'permissions_added' => $this->sorted($copiedPermissions),
                'permissions_removed' => [],
                'permission_count' => count($copiedPermissions),
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
