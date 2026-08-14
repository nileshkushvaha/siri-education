<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Booking\Exceptions\BookingException;
use App\Booking\Services\PaymentProviderResolver;
use App\Models\InstructorPackageProposal;
use App\Models\Payment;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Package\Enums\PackagePurchaseStatus;
use App\Package\Exceptions\PackageException;
use App\Payments\DTOs\PaymentCheckoutData;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Services\PaymentCheckoutService;
use App\Payments\Services\PaymentService;
use App\Services\AuditTrailService;
use Illuminate\Support\Str;

/**
 * Phase 4B.2 — the package domain's owner of "what the student bought
 * and whether it has been paid for".
 *
 * Knows package business rules only: who may pay, for what, in which
 * currency. Attempt mechanics belong to PaymentService, gateway
 * orders to PaymentCheckoutService, and the provider APIs to the
 * shared gateway clients. No Razorpay/Stripe detail appears here.
 *
 * PENDING vs PAID: this service never marks a purchase Paid. That
 * transition belongs to PackagePurchaseSettlementService, which does it
 * atomically with entitlement activation from a verified provider
 * event. Nothing student-initiated may settle a payment.
 */
final class PackagePurchaseService
{
    private const string LOG_NAME = 'student_package_purchases';

    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly PaymentCheckoutService $checkout,
        private readonly PaymentProviderResolver $providers,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Creates the purchase for a just-accepted proposal. Called only by
     * InstructorPackageProposalService::acceptProposal(), inside that
     * method's transaction, so a proposal can never be Accepted without
     * its purchase existing.
     *
     * The commercial terms are COPIED from the approved proposal — the
     * pricing matrix is deliberately never consulted again. A price
     * change one second after acceptance must not alter what the
     * student agreed to pay.
     *
     * @throws PackageException when the proposal carries no approved final price
     */
    public function createFromAcceptedProposal(InstructorPackageProposal $proposal): StudentPackagePurchase
    {
        if ($proposal->final_price_minor === null || $proposal->final_price_minor < 1) {
            throw new PackageException('This package has no approved price and cannot be purchased.');
        }

        if (blank($proposal->currency_code)) {
            throw new PackageException('This package has no approved currency and cannot be purchased.');
        }

        return StudentPackagePurchase::query()->create([
            'proposal_id' => $proposal->id,
            'student_id' => $proposal->student_id,
            'reference' => $this->newReference(),
            'amount_minor' => $proposal->final_price_minor,
            'currency_id' => $proposal->currency_id,
            'currency_code' => $proposal->currency_code,
            'status' => PackagePurchaseStatus::PendingPayment,
            'accepted_at' => now(),
        ]);
    }

    /**
     * Starts (or safely resumes) gateway checkout for the student's own
     * pending purchase.
     *
     * Amount and currency come from the purchase snapshot alone; no
     * caller-supplied money value is trusted, and the provider is
     * resolved server-side through the platform's existing gateway
     * configuration rather than accepted from the browser.
     *
     * @throws PackageException on any ownership/state/provider failure
     */
    public function startCheckout(StudentPackagePurchase $purchase, User $student): PaymentCheckoutData
    {
        $this->assertOwnedAndPayable($purchase, $student);

        $provider = $this->resolveProviderFor($student, $purchase);

        try {
            $checkout = $this->checkout->start($purchase, $provider);
        } catch (PaymentException $e) {
            throw new PackageException($e->getMessage());
        }

        $this->audit->logUser(
            $student,
            self::LOG_NAME,
            $checkout->resumed ? 'package_checkout_resumed' : 'package_checkout_started',
            $checkout->resumed
                ? sprintf('Package checkout resumed for %s.', $purchase->reference)
                : sprintf('Package checkout started for %s via %s.', $purchase->reference, $provider),
            $purchase,
            $this->metadata($purchase, ['provider' => $checkout->provider, 'payment_id' => $checkout->paymentId]),
        );

        return $checkout;
    }

    /**
     * Abandons the open attempt so the student can start a clean one.
     * The purchase itself is untouched and stays PendingPayment — a
     * cancelled attempt is not a cancelled purchase.
     *
     * @throws PackageException when nothing is open to cancel
     */
    public function cancelOpenAttempt(StudentPackagePurchase $purchase, User $student): Payment
    {
        $this->assertOwnedAndPayable($purchase, $student);

        try {
            $cancelled = $this->checkout->cancelOpenAttempt($purchase, 'Cancelled by the student.');
        } catch (PaymentException $e) {
            throw new PackageException($e->getMessage());
        }

        $this->audit->logUser(
            $student,
            self::LOG_NAME,
            'package_payment_attempt_cancelled',
            sprintf('Package payment attempt cancelled for %s.', $purchase->reference),
            $purchase,
            $this->metadata($purchase, ['payment_id' => $cancelled->id]),
        );

        return $cancelled;
    }

    /** The attempt currently awaiting a provider outcome, if any. */
    public function openAttemptFor(StudentPackagePurchase $purchase): ?Payment
    {
        return $this->checkout->openAttemptFor($purchase);
    }

    /**
     * A payment the provider has confirmed, on a purchase that has not
     * caught up yet.
     *
     * This is the interrupted-settlement window: settlement rolled back
     * after a Paid attempt was recorded, or a webhook is still in
     * flight. It is brief and self-healing (the reconciliation sweep
     * closes it), but while it lasts the student must see "activating",
     * never a Pay button.
     */
    public function isAwaitingActivation(StudentPackagePurchase $purchase): bool
    {
        return $purchase->status->isPayable() && $this->payments->isPaid($purchase);
    }

    /** @throws PackageException */
    private function assertOwnedAndPayable(StudentPackagePurchase $purchase, User $student): void
    {
        if ((int) $purchase->student_id !== (int) $student->id) {
            throw new PackageException('This purchase does not belong to you.');
        }

        if (! $purchase->status->isPayable()) {
            throw new PackageException(sprintf('This package is %s and cannot be paid for again.', $purchase->status->label()));
        }

        // The purchase status is NOT a sufficient paid-guard on its
        // own. If a settled attempt exists, the money is already
        // collected — charging again would be the worst error this
        // domain can make, so no new gateway order is ever created here
        // even though the purchase still reads pending_payment.
        // Reconciliation resolves the lag; the student waits.
        if ($this->payments->isPaid($purchase)) {
            throw new PackageException('We have already received your payment for this package. It is being activated now — please check back shortly.');
        }

        if ($purchase->amount_minor < 1) {
            throw new PackageException('This purchase has no payable amount.');
        }
    }

    /**
     * Server-side provider selection through the platform's existing
     * routing (country routing -> default provider -> booking setting),
     * plus the kill switch, allowed-provider list, and credential
     * validation that resolver already enforces. No package-specific
     * routing policy is introduced.
     *
     * @throws PackageException when no configured provider can take this currency
     */
    private function resolveProviderFor(User $student, StudentPackagePurchase $purchase): string
    {
        try {
            $provider = $this->providers->current($student->profile?->country?->iso2);
        } catch (BookingException $e) {
            throw new PackageException($e->getMessage());
        }

        $supported = array_map(strtoupper(...), $provider->supportedCurrencies());

        if (! in_array(strtoupper((string) $purchase->currency_code), $supported, true)) {
            throw new PackageException('No payment method is available for this package\'s currency.');
        }

        return $provider->key();
    }

    /** Short, unique, support-quotable — the same shape as the wallet's WRCH- reference. */
    private function newReference(): string
    {
        return 'PKG-'.strtoupper(Str::random(12));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function metadata(StudentPackagePurchase $purchase, array $extra = []): array
    {
        return [
            'purchase_reference' => $purchase->reference,
            'proposal_id' => $purchase->proposal_id,
            'student_id' => $purchase->student_id,
            'amount_minor' => $purchase->amount_minor,
            'currency_code' => $purchase->currency_code,
            'status' => $purchase->status->value,
            ...$extra,
        ];
    }
}
