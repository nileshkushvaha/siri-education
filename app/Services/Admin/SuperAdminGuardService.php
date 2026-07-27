<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\CanonicalSuperAdminRoleProtectedException;
use App\Exceptions\LastActiveSuperAdminException;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * The single authority protecting the
 * platform-wide invariant `active_access_capable_super_admin_count >= 1`.
 *
 * "Active, access-capable Super Admin" is deliberately NOT a new
 * concept — it is exactly the condition that already grants panel
 * access for a Super Admin (see User::canAccessPanel(): for a Super
 * Admin, PortalResolver::usesAdminPortal() is unconditionally true, so
 * the only real gate is User::isActive()). Centralized here so the
 * guard, Filament, and every test share one definition.
 *
 * Every mutation that could reduce the count (role removal, status
 * change, deletion — single or bulk) must run through protect()/
 * protectBatch(), which serialize on a single named MySQL lock spanning
 * the ENTIRE mutation + re-check, not just the read. A plain "count
 * then update" is not enough: two concurrent requests each disabling a
 * different one of the last two active Super Admins could both observe
 * the other as still active and both commit, leaving zero. The lock is
 * acquired BEFORE any transaction begins and released only after
 * commit/rollback, so the second caller's transaction can only start
 * once the first has fully resolved — its reads are then guaranteed
 * fresh. `lockForUpdate()` is used defensively on top of that for the
 * same reason BookingRepository::withInstructorLock() pairs a named
 * lock with row locks: belt and suspenders, not either/or.
 */
final class SuperAdminGuardService
{
    public const string SUPER_ADMIN_ROLE = 'super_admin';

    private const string LOCK_NAME = 'super_admin:lifecycle';

    private const int LOCK_TIMEOUT_SECONDS = 10;

    /** Whether $user currently counts toward the invariant. */
    public function isActiveSuperAdmin(User $user): bool
    {
        return $user->hasRole(self::SUPER_ADMIN_ROLE) && $user->isActive();
    }

    /** Count of active, access-capable Super Admins other than $excluding. */
    public function countOtherActiveSuperAdmins(User $excluding): int
    {
        return User::role(self::SUPER_ADMIN_ROLE)
            ->where('status', User::STATUS_ACTIVE)
            ->where('id', '!=', $excluding->getKey())
            ->count();
    }

    /**
     * UI-only hint (e.g. hiding a destructive Filament action) — never
     * the authority. The real enforcement is protect()/protectBatch(),
     * re-checked under the lock regardless of what this returned when
     * the page was rendered.
     */
    public function isLastActiveSuperAdmin(User $user): bool
    {
        return $this->isActiveSuperAdmin($user) && $this->countOtherActiveSuperAdmins($user) === 0;
    }

    /**
     * Runs $mutate against a freshly locked copy of $target inside the
     * global lifecycle lock + a transaction, then verifies the
     * invariant still holds; throws (rolling back the transaction, the
     * mutation included) if it does not.
     *
     * @template TResult
     *
     * @param  Closure(User): TResult  $mutate
     * @return TResult
     */
    public function protect(User $target, Closure $mutate): mixed
    {
        return $this->withLock(fn () => DB::transaction(fn () => $this->guardOne($target, $mutate)));
    }

    /**
     * Same as protect(), but evaluates a whole batch as one proposed
     * mutation set: if applying every mutation in the batch would leave
     * zero active Super Admins, the ENTIRE batch is rolled back — never
     * just the row that happened to trip the check.
     *
     * @param  iterable<User>  $targets
     * @param  Closure(User): mixed  $mutate
     */
    public function protectBatch(iterable $targets, Closure $mutate): void
    {
        $this->withLock(function () use ($targets, $mutate): void {
            DB::transaction(function () use ($targets, $mutate): void {
                foreach ($targets as $target) {
                    $this->guardOne($target, $mutate);
                }
            });
        });
    }

    /**
     * Blocks deletion of the canonical super_admin Role record — every
     * authorization path recognizes Super Admin access by this exact
     * role NAME, so deleting it would silently strip access from every
     * Super Admin at once, bypassing the per-user invariant entirely.
     */
    public function assertRoleNotCanonical(Role $role): void
    {
        if ($role->name === self::SUPER_ADMIN_ROLE) {
            throw CanonicalSuperAdminRoleProtectedException::forDeletion();
        }
    }

    /** Blocks renaming the canonical role away from its recognized name. */
    public function assertCanonicalRoleNameUnchanged(Role $role, string $newName): void
    {
        if ($role->name === self::SUPER_ADMIN_ROLE && $newName !== self::SUPER_ADMIN_ROLE) {
            throw CanonicalSuperAdminRoleProtectedException::forRename();
        }
    }

    /**
     * Re-checks the invariant for a mutation already applied elsewhere
     * (e.g. Filament's own transaction-wrapped save lifecycle, where
     * the role/status update already ran via handleRecordUpdate()
     * before this page's afterSave() hook fires). Must be called while
     * the lifecycle lock is held for the whole request — see
     * acquireLock()/releaseLock() and EditUser::save().
     */
    public function assertInvariantAfterMutation(User $target, bool $wasActiveSuperAdminBeforeMutation): void
    {
        if (! $wasActiveSuperAdminBeforeMutation) {
            return;
        }

        $after = User::query()->lockForUpdate()->find($target->getKey());
        $stillCounts = $after !== null && $this->isActiveSuperAdmin($after);

        if ($stillCounts) {
            return;
        }

        $others = User::role(self::SUPER_ADMIN_ROLE)
            ->where('status', User::STATUS_ACTIVE)
            ->where('id', '!=', $target->getKey())
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw LastActiveSuperAdminException::make();
        }
    }

    /**
     * For callers (e.g. EditUser::save()) that need the lock held
     * across a lifecycle they don't control end-to-end (Filament's own
     * begin/commit transaction). Always release via releaseLock() in a
     * finally block.
     */
    public function acquireLock(): void
    {
        $granted = DB::selectOne('SELECT GET_LOCK(?, ?) AS granted', [self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS]);

        if ((int) ($granted->granted ?? 0) !== 1) {
            throw new RuntimeException('Could not acquire the Super Admin lifecycle lock.');
        }
    }

    public function releaseLock(): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
    }

    private function withLock(Closure $callback): mixed
    {
        $this->acquireLock();

        try {
            return $callback();
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(User): TResult  $mutate
     * @return TResult
     */
    private function guardOne(User $target, Closure $mutate): mixed
    {
        $fresh = User::query()->lockForUpdate()->findOrFail($target->getKey());
        $wasActiveSuperAdmin = $this->isActiveSuperAdmin($fresh);

        $result = $mutate($fresh);

        $this->assertInvariantAfterMutation($target, $wasActiveSuperAdmin);

        return $result;
    }
}
