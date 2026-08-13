<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorPackageProposal;
use App\Models\User;
use App\Package\Enums\InstructorPackageProposalStatus;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Instructor: create/submit/cancel their own proposals, view their
 * own. Admin: review/approve/reject/override via explicit permissions.
 * Student: view only, and only once Approved/Accepted — never a Draft/
 * Submitted/Rejected proposal about them. `super_admin` bypasses via
 * Gate::before() — never replicated here.
 *
 * The specific-student eligibility check (does this instructor have a
 * real relationship with this student) is a deliberate Service-level
 * business rule, not a Policy concern — see
 * InstructorPackageProposalService::hasValidRelationship(). This
 * policy only gates "can this role touch the feature at all."
 */
class InstructorPackageProposalPolicy
{
    use HandlesAuthorization;

    /**
     * Gates the Filament ADMIN listing only (all proposals, every
     * instructor/student) — never called by the instructor/student
     * Livewire pages, which list their own records directly (scoped by
     * instructor_id/student_id in the query) without a viewAny check.
     * Even if this returned true for a non-admin role, App\Models\
     * User::canAccessPanel() still blocks the entire /admin panel for
     * every role except super_admin/manager — the same structural
     * guarantee StudentLessonPriceResource relies on.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorPackageProposal');
    }

    public function view(User $user, InstructorPackageProposal $proposal): bool
    {
        if ($this->hasPermission($user, 'ViewAny:InstructorPackageProposal')) {
            return true;
        }

        if ($user->id === $proposal->instructor_id) {
            return true;
        }

        if ($user->id === $proposal->student_id) {
            return in_array($proposal->status, [
                InstructorPackageProposalStatus::Approved,
                InstructorPackageProposalStatus::Accepted,
            ], true);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:InstructorPackageProposal');
    }

    public function submit(User $user, InstructorPackageProposal $proposal): bool
    {
        return $user->id === $proposal->instructor_id
            && $proposal->status === InstructorPackageProposalStatus::Draft;
    }

    public function cancel(User $user, InstructorPackageProposal $proposal): bool
    {
        if ($proposal->status->isTerminal()) {
            return false;
        }

        return $user->id === $proposal->instructor_id
            || $this->hasPermission($user, 'Cancel:InstructorPackageProposal');
    }

    public function approve(User $user, InstructorPackageProposal $proposal): bool
    {
        return $proposal->status === InstructorPackageProposalStatus::Submitted
            && $this->hasPermission($user, 'Approve:InstructorPackageProposal');
    }

    public function reject(User $user, InstructorPackageProposal $proposal): bool
    {
        return $proposal->status === InstructorPackageProposalStatus::Submitted
            && $this->hasPermission($user, 'Reject:InstructorPackageProposal');
    }

    public function overridePrice(User $user, InstructorPackageProposal $proposal): bool
    {
        return $proposal->status === InstructorPackageProposalStatus::Submitted
            && $this->hasPermission($user, 'OverridePrice:InstructorPackageProposal');
    }

    public function accept(User $user, InstructorPackageProposal $proposal): bool
    {
        return $user->id === $proposal->student_id
            && $proposal->status === InstructorPackageProposalStatus::Approved
            && $this->hasPermission($user, 'Accept:InstructorPackageProposal');
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
