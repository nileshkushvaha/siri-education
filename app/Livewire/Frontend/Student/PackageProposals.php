<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\InstructorPackageProposal;
use App\Models\StudentPackageEntitlement;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Student-facing package view. The list query is inherently ownership +
 * visibility scoped (own proposals, Approved/Accepted only — never
 * Draft/Submitted/Rejected) — mirrors BookingHistory: server-scoped
 * list + Gate::authorize() per action, no ownership logic duplicated
 * here.
 *
 * Phase 4A: accepting now grants a StudentPackageEntitlement (the
 * lesson balance shown alongside each accepted package). Payment is
 * still out of scope — accepting records agreement and grants the
 * balance; no money moves and no lesson is scheduled here.
 */
final class PackageProposals extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $statusMessage = '';

    private InstructorPackageProposalService $proposals;

    public function boot(InstructorPackageProposalService $proposals): void
    {
        $this->proposals = $proposals;
    }

    public function accept(string $proposalId): void
    {
        $proposal = $this->ownProposal($proposalId);

        if (! $this->authorizeOrDeny('accept', $proposal)) {
            return;
        }

        try {
            $accepted = $this->proposals->acceptProposal($proposal, auth()->user());
            $this->statusMessage = sprintf(
                'Package accepted — %d lessons are now available. Payment is handled separately.',
                $accepted->total_quantity,
            );
        } catch (PackageException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function decline(string $proposalId): void
    {
        $proposal = $this->ownProposal($proposalId);

        if (! $this->authorizeOrDeny('decline', $proposal)) {
            return;
        }

        try {
            $this->proposals->declineProposal($proposal, auth()->user());
            $this->statusMessage = 'Package declined.';
        } catch (PackageException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function render(): View
    {
        $studentId = (int) auth()->id();

        return view('livewire.frontend.student.package-proposals', [
            'proposals' => InstructorPackageProposal::query()
                ->forStudent($studentId)
                ->visibleToStudent()
                ->with(['instructor', 'packageBenefitRule'])
                ->orderByDesc('approved_at')
                ->paginate(10),
            // Keyed by proposal_id so the blade can show the live lesson
            // balance next to an accepted package without an N+1.
            'entitlements' => StudentPackageEntitlement::query()
                ->forStudent($studentId)
                ->get()
                ->keyBy('proposal_id'),
        ]);
    }

    private function ownProposal(string $proposalId): InstructorPackageProposal
    {
        return InstructorPackageProposal::query()
            ->forStudent((int) auth()->id())
            ->findOrFail($proposalId);
    }

    private function authorizeOrDeny(string $ability, mixed $arg): bool
    {
        try {
            $this->authorize($ability, $arg);

            return true;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage() ?: 'You are not authorized to perform this action.');

            return false;
        }
    }
}
