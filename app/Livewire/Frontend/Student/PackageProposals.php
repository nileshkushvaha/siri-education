<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\InstructorPackageProposal;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Student-facing read-only package view. The list query is inherently
 * ownership + visibility scoped (own proposals, Approved/Accepted
 * only — never Draft/Submitted/Rejected) — mirrors BookingHistory:
 * server-scoped list + Gate::authorize() per action, no ownership
 * logic duplicated here. "Accept" is a placeholder per this phase's
 * explicit scope: no payment flow exists behind it yet.
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
        $proposal = InstructorPackageProposal::query()
            ->forStudent((int) auth()->id())
            ->findOrFail($proposalId);

        if (! $this->authorizeOrDeny('accept', $proposal)) {
            return;
        }

        try {
            $this->proposals->accept($proposal, auth()->user());
            $this->statusMessage = 'Package accepted. Payment and lesson access are handled separately.';
        } catch (PackageException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.frontend.student.package-proposals', [
            'proposals' => InstructorPackageProposal::query()
                ->forStudent((int) auth()->id())
                ->visibleToStudent()
                ->with(['instructor', 'packageBenefitRule'])
                ->orderByDesc('approved_at')
                ->paginate(10),
        ]);
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
