<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorSettlementBatch;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Settlement batch authorization. The instructor may view their own
 * batches; creating/approving/paying/cancelling are staff permissions
 * — marking paid deliberately has its own permission (MarkPaid) so it
 * can be restricted more tightly than batch creation.
 */
class InstructorSettlementBatchPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorSettlementBatch');
    }

    public function view(User $user, InstructorSettlementBatch $batch): bool
    {
        return $user->id === $batch->instructor_id
            || $this->hasPermission($user, 'View:InstructorSettlementBatch');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:InstructorSettlementBatch');
    }

    public function update(User $user, InstructorSettlementBatch $batch): bool
    {
        return $this->hasPermission($user, 'Update:InstructorSettlementBatch');
    }

    public function delete(User $user, InstructorSettlementBatch $batch): bool
    {
        return $this->hasPermission($user, 'Delete:InstructorSettlementBatch');
    }

    public function approve(User $user, InstructorSettlementBatch $batch): bool
    {
        return $this->hasPermission($user, 'Approve:InstructorSettlementBatch');
    }

    public function markPaid(User $user, InstructorSettlementBatch $batch): bool
    {
        return $this->hasPermission($user, 'MarkPaid:InstructorSettlementBatch');
    }

    public function cancel(User $user, InstructorSettlementBatch $batch): bool
    {
        return $this->hasPermission($user, 'Cancel:InstructorSettlementBatch');
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
