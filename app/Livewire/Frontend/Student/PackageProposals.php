<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\InstructorPackageProposal;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackagePurchaseService;
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
 * Accepting creates a PendingPayment StudentPackagePurchase; the
 * student then pays for it, and verified settlement (Phase 4B.3)
 * activates the lesson balance. Three display states follow from that:
 * payment pending (pay/continue/cancel), payment received but
 * activation still catching up (no Pay button at all — see
 * isAwaitingActivation()), and active with a live balance and expiry.
 *
 * Amount, currency, and provider are never accepted from the browser;
 * every action passes only a purchase id and re-resolves the rest
 * server-side.
 */
final class PackageProposals extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $statusMessage = '';

    /** Local/testing only — the fake provider has no hosted checkout to hand off to. */
    public ?array $pendingFakeCheckout = null;

    private InstructorPackageProposalService $proposals;

    private PackagePurchaseService $purchases;

    public function boot(InstructorPackageProposalService $proposals, PackagePurchaseService $purchases): void
    {
        $this->proposals = $proposals;
        $this->purchases = $purchases;
    }

    public function accept(string $proposalId): void
    {
        $proposal = $this->ownProposal($proposalId);

        if (! $this->authorizeOrDeny('accept', $proposal)) {
            return;
        }

        try {
            $this->proposals->acceptProposal($proposal, auth()->user());
            $this->statusMessage = 'Package accepted. Complete payment to activate your lessons.';
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

    /** Starts checkout, or safely resumes the attempt already in progress. */
    public function pay(string $purchaseId): void
    {
        $this->statusMessage = '';
        $this->pendingFakeCheckout = null;

        $purchase = $this->ownPurchase($purchaseId);

        if (! $this->authorizeOrDeny('pay', $purchase)) {
            return;
        }

        try {
            $checkout = $this->purchases->startCheckout($purchase, auth()->user());
        } catch (PackageException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        if ($checkout->provider === 'razorpay') {
            $this->dispatch(
                'package-checkout-ready',
                orderId: $checkout->checkoutPayload['order_id'],
                keyId: $checkout->checkoutPayload['key_id'],
                amountMinor: $checkout->amountMinor,
                currency: $checkout->currencyCode,
            );

            return;
        }

        if ($checkout->provider === 'stripe') {
            $this->dispatch(
                'package-stripe-checkout-ready',
                clientSecret: $checkout->checkoutPayload['client_secret'],
                publishableKey: $checkout->checkoutPayload['publishable_key'],
                amountMinor: $checkout->amountMinor,
                currency: $checkout->currencyCode,
            );

            return;
        }

        $this->pendingFakeCheckout = ['reference' => $checkout->reference];
    }

    /** Abandons the open attempt. The purchase itself stays payable. */
    public function cancelPaymentAttempt(string $purchaseId): void
    {
        $this->statusMessage = '';
        $this->pendingFakeCheckout = null;

        $purchase = $this->ownPurchase($purchaseId);

        if (! $this->authorizeOrDeny('cancelPaymentAttempt', $purchase)) {
            return;
        }

        try {
            $this->purchases->cancelOpenAttempt($purchase, auth()->user());
            $this->statusMessage = 'Payment cancelled. You can start a new payment whenever you are ready.';
        } catch (PackageException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function render(): View
    {
        $studentId = (int) auth()->id();

        $purchases = StudentPackagePurchase::query()
            ->forStudent($studentId)
            ->with('payments')
            ->get()
            ->keyBy('proposal_id');

        return view('livewire.frontend.student.package-proposals', [
            'proposals' => InstructorPackageProposal::query()
                ->forStudent($studentId)
                ->visibleToStudent()
                ->with(['instructor', 'packageBenefitRule'])
                ->orderByDesc('approved_at')
                ->paginate(10),
            'purchases' => $purchases,
            // A confirmed payment on a not-yet-activated purchase must
            // never show a Pay button; reconciliation closes the gap.
            'awaitingActivation' => $purchases
                ->filter(fn (StudentPackagePurchase $purchase): bool => $this->purchases->isAwaitingActivation($purchase))
                ->keys()
                ->all(),
            // Keyed by proposal_id so the blade can show a live lesson
            // balance and expiry without an N+1. Populated only once a
            // payment has actually settled.
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

    private function ownPurchase(string $purchaseId): StudentPackagePurchase
    {
        return StudentPackagePurchase::query()
            ->forStudent((int) auth()->id())
            ->findOrFail($purchaseId);
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
