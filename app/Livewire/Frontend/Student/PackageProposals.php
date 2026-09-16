<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\InstructorPackageProposal;
use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Package\DTOs\PackageSettlementResult;
use App\Package\Enums\PackagePurchaseStatus;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackagePurchaseReconciliationService;
use App\Package\Services\PackagePurchaseService;
use App\Package\Services\PackagePurchaseSettlementService;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Services\PaymentCallbackVerifier;
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

    /** The Razorpay attempt being confirmed after the student returns from checkout. */
    public ?string $pendingPaymentId = null;

    /** The Stripe attempt whose Payment Element is mounted on this page. */
    public ?string $pendingStripePaymentId = null;

    public int $paymentPollCount = 0;

    public const int MAX_PAYMENT_POLLS = 40;

    public const int PROVIDER_RECHECK_SECONDS = 5;

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
        $this->clearPendingPayment();

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
                purchaseId: (string) $purchase->id,
                orderId: $checkout->checkoutPayload['order_id'],
                keyId: $checkout->checkoutPayload['key_id'],
                amountMinor: $checkout->amountMinor,
                currency: $checkout->currencyCode,
                name: auth()->user()?->name ?? '',
                email: auth()->user()?->email ?? '',
            );

            return;
        }

        if ($checkout->provider === 'stripe') {
            $this->pendingStripePaymentId = $checkout->paymentId;
            $this->dispatch(
                'package-stripe-checkout-ready',
                clientSecret: $checkout->checkoutPayload['client_secret'],
                publishableKey: $checkout->checkoutPayload['publishable_key'],
                amountMinor: $checkout->amountMinor,
                currency: $checkout->currencyCode,
            );

            return;
        }

        $this->pendingFakeCheckout = ['reference' => $checkout->reference, 'payment_id' => $checkout->paymentId];
    }

    /**
     * Razorpay Checkout.js success callback.
     *
     * The browser is never the authority: PaymentCallbackVerifier proves
     * the signature and that the order belongs to THIS student's purchase,
     * then the same server-to-server confirmation the reconciliation
     * sweep uses runs right now. If Razorpay reports the order paid, the
     * purchase is settled and the lessons unlocked in this very request;
     * otherwise the page polls (pollPackagePaymentStatus). The signed
     * webhook and the scheduled sweep remain the safety nets.
     */
    public function verifyPackagePayment(string $purchaseId, string $orderId, string $paymentId, string $signature): void
    {
        $this->statusMessage = '';
        $this->resetErrorBag('form');

        $purchase = $this->ownPurchase($purchaseId);

        try {
            $payment = app(PaymentCallbackVerifier::class)->verifyRazorpayCheckout($purchase, $orderId, $paymentId, $signature);
        } catch (PaymentException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->confirmWithProvider($payment);
        $this->trackPendingPayment($payment->refresh(), $purchase->refresh());
    }

    /** Razorpay Checkout.js closed without paying — nothing was taken; the purchase stays payable. */
    public function packageCheckoutDismissed(string $purchaseId): void
    {
        if ($this->pendingPaymentId !== null) {
            return;
        }

        $purchase = $this->ownPurchase($purchaseId);
        $open = $this->purchases->openAttemptFor($purchase);

        if ($open !== null && $open->provider_payment_id === null) {
            $this->statusMessage = 'The payment window was closed before completing. No money was taken — you can continue the payment whenever you are ready.';
        }
    }

    /**
     * Polled while a payment the student just made is not yet confirmed
     * (Razorpay: wire:poll; Stripe: the checkout script). Re-reads the
     * server's record and, throttled, asks the provider again through
     * the reconciliation path, so a capture that lands seconds after the
     * callback unlocks the lessons seconds later, not on the next sweep.
     */
    public function pollPackagePaymentStatus(): void
    {
        $paymentId = $this->pendingPaymentId ?? $this->pendingStripePaymentId;

        if ($paymentId === null) {
            return;
        }

        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('user_id', auth()->id())
            ->where('payable_type', StudentPackagePurchase::PAYABLE_TYPE)
            ->first();
        $purchase = $payment === null ? null : StudentPackagePurchase::query()
            ->forStudent((int) auth()->id())
            ->find($payment->payable_id);

        if ($payment === null || $purchase === null) {
            $this->clearPendingPayment();

            return;
        }

        if ($payment->status->isOpen()) {
            $this->paymentPollCount++;

            if ($this->paymentPollCount > self::MAX_PAYMENT_POLLS) {
                $this->statusMessage = 'Your payment is taking longer than usual to confirm. If it went through, your lessons will unlock automatically and we will email you — there is no need to pay again.';
                $this->clearPendingPayment();

                return;
            }

            $this->confirmWithProvider($payment);
            $payment->refresh();
        }

        $this->trackPendingPayment($payment, $purchase->refresh());
    }

    /**
     * Local/testing-only: the fake provider has no checkout UI. Goes
     * through the SAME PackagePurchaseSettlementService a signed webhook
     * reaches — see WalletOverview::simulateFakeRecharge().
     */
    public function simulateFakePackagePayment(bool $success): void
    {
        if (! app()->environment(['local', 'testing']) || $this->pendingFakeCheckout === null) {
            return;
        }

        $this->statusMessage = '';
        $this->resetErrorBag('form');

        $payment = Payment::query()
            ->whereKey((string) ($this->pendingFakeCheckout['payment_id'] ?? ''))
            ->where('user_id', auth()->id())
            ->where('payable_type', StudentPackagePurchase::PAYABLE_TYPE)
            ->first();
        $purchase = $payment === null ? null : StudentPackagePurchase::query()
            ->forStudent((int) auth()->id())
            ->find($payment->payable_id);

        $this->pendingFakeCheckout = null;

        if ($payment === null || $purchase === null) {
            return;
        }

        $result = app(PackagePurchaseSettlementService::class)->settle($payment, new VerifiedPaymentEvent(
            provider: (string) $payment->provider,
            type: $success ? PaymentEventType::Succeeded : PaymentEventType::Failed,
            reference: $payment->idempotency_key,
            providerOrderId: $payment->provider_order_id,
            providerPaymentId: 'fake_payment_'.$payment->id,
            amountMinor: (int) $payment->amount_minor,
            currencyCode: (string) $payment->currency_code,
            reason: $success ? null : 'Simulated failure (fake provider).',
        ));

        $this->trackPendingPayment($payment->refresh(), ($result->purchase ?? $purchase)->refresh());
    }

    /**
     * Asks the provider, server to server, whether this attempt is paid
     * and settles it through the ONE settlement path if so. Idempotent:
     * an attempt the webhook already settled is a replay. Throttled so a
     * polling page never hammers the gateway.
     */
    private function confirmWithProvider(Payment $payment): ?PackageSettlementResult
    {
        $lastChecked = $payment->last_synced_at;

        if ($lastChecked !== null && $lastChecked->gt(now()->subSeconds(self::PROVIDER_RECHECK_SECONDS))) {
            return null;
        }

        return app(PackagePurchaseReconciliationService::class)->reconcileOne($payment);
    }

    /** Applies the attempt's current state to the page and arms or disarms polling. */
    private function trackPendingPayment(Payment $payment, StudentPackagePurchase $purchase): void
    {
        if ($purchase->status === PackagePurchaseStatus::Paid) {
            $this->clearPendingPayment();
            $this->statusMessage = 'Payment received — your lessons are ready to book.';

            return;
        }

        if ($payment->status->isTerminal() && $payment->status !== PaymentStatus::Paid) {
            $this->clearPendingPayment();
            $this->addError('form', 'Your payment could not be completed. Nothing was taken — you can try again.');

            return;
        }

        if ($payment->status === PaymentStatus::Paid) {
            // Captured, activation still to run (reconciliation closes the gap).
            $this->clearPendingPayment();
            $this->statusMessage = 'Your payment was received. We are unlocking your lessons — this takes a moment.';

            return;
        }

        if ($this->pendingStripePaymentId === null && $this->pendingPaymentId !== $payment->id) {
            $this->pendingPaymentId = $payment->id;
            $this->paymentPollCount = 0;
        }
    }

    private function clearPendingPayment(): void
    {
        $this->pendingPaymentId = null;
        $this->pendingStripePaymentId = null;
        $this->paymentPollCount = 0;
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
