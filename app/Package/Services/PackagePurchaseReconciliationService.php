<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\Payment;
use App\Models\StudentPackagePurchase;
use App\Package\DTOs\PackageSettlementResult;
use App\Package\Exceptions\PackageException;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Services\PaymentReconciliationIssueService;
use App\Payments\Services\PaymentService;
use App\Services\AuditTrailService;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;

/**
 * Phase 4B.3 — the safety net behind the webhook.
 *
 * Webhooks get lost, arrive late, or hit a settlement that rolled back.
 * This sweep asks the provider directly, and when the provider's own
 * authenticated answer is "paid", it calls the SAME
 * PackagePurchaseSettlementService the webhook uses. There is no second
 * settlement implementation to keep in agreement — this class only
 * decides *whether* the provider says paid, never *what that means*.
 *
 * Mirrors WalletRechargeReconciliationService's shape deliberately:
 * a `DUE_AFTER_MINUTES` grace period, a `last_synced_at` throttle, an
 * authenticated fetch, and never a guess — only an explicit provider
 * "paid"/"succeeded" ever settles anything.
 */
final class PackagePurchaseReconciliationService
{
    /** How long an open attempt waits before the first provider poll. */
    public const int DUE_AFTER_MINUTES = 10;

    /**
     * PAY-1 — how long an attempt may stay unresolved before it stops
     * being "in flight" and becomes something an operator should look
     * at.
     *
     * Six times the poll grace period, so an attempt has had roughly an
     * hour and a dozen sweeps to resolve itself before anyone is
     * bothered. Derived from the existing reconciliation cadence rather
     * than invented: nothing here fails a payment, it only makes a
     * stuck one visible, so the constant governs noise, never money.
     */
    public const int OPERATOR_VISIBLE_AFTER_MINUTES = self::DUE_AFTER_MINUTES * 6;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PackagePurchaseSettlementService $settlement,
        private readonly RazorpayGatewayClient $razorpay,
        private readonly StripeGatewayClient $stripe,
        private readonly PaymentGatewaySettings $gatewaySettings,
        private readonly AuditTrailService $audit,
        private readonly PaymentReconciliationIssueService $issues,
    ) {}

    /** @return int how many attempts were examined */
    public function reconcileDue(int $limit = 200): int
    {
        $due = Payment::query()
            ->where('payable_type', StudentPackagePurchase::PAYABLE_TYPE)
            ->reconciliationDue(now()->subMinutes(self::DUE_AFTER_MINUTES))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $payment) {
            $this->reconcileOne($payment);
        }

        return $due->count();
    }

    /**
     * Polls one attempt and settles it if the provider confirms
     * payment. Safe to call repeatedly: settlement itself is
     * idempotent, so a recovered attempt that is already Paid simply
     * comes back as a replay.
     */
    public function reconcileOne(Payment $payment): PackageSettlementResult
    {
        if ($payment->payable_type !== StudentPackagePurchase::PAYABLE_TYPE) {
            return PackageSettlementResult::ignored($payment, null, 'Not a package payment.');
        }

        if (! $payment->status->isOpen() || $payment->provider_order_id === null) {
            // PAY-1: an OPEN attempt with no provider reference is not
            // "nothing to reconcile" — it is a checkout that started and
            // never reached the gateway, and no amount of polling will
            // ever fix it because there is nothing to poll. It needs a
            // human, so it stops exiting silently here.
            if ($payment->status->isOpen()) {
                $this->detectStuckAttempt($payment);
            }

            $this->payments->markSynced($payment);

            return PackageSettlementResult::ignored($payment, null, 'Nothing to reconcile.');
        }

        $reachable = true;
        $confirmed = $this->providerConfirmsPayment($payment, $reachable);

        if ($confirmed === null) {
            // PAY-1 (PAY-AUD-001): previously both "the provider says
            // not yet" and "we could not reach the provider at all"
            // exited here identically and silently. They are different
            // facts and only one of them needs a human.
            if (! $reachable) {
                $this->recordOperationalIssue($payment, PaymentReconciliationIssueType::ProviderUnavailable);
            } else {
                $this->detectStuckAttempt($payment);
            }

            $this->payments->markSynced($payment);

            return PackageSettlementResult::ignored($payment, null, 'The provider has not confirmed this payment.');
        }

        try {
            $result = $this->settlement->settle($payment, $confirmed);
        } catch (PackageException $e) {
            // Left open on purpose: the next sweep retries. This is the
            // recovery path for "money collected, activation failed".
            //
            // PAY-1: it is also the single worst state this queue can
            // hold — the provider has confirmed the money and the
            // student has nothing — so it stops being an audit-log-only
            // event and becomes an operator incident. Raised
            // unconditionally, with no grace window: unlike an
            // unreachable gateway, there is nothing transient about it.
            $this->payments->markSynced($payment);

            $this->issues->record(
                $payment,
                PaymentReconciliationIssueType::SettlementFailed,
                [
                    'expected_amount_minor' => (int) $payment->amount_minor,
                    'expected_currency' => (string) $payment->currency_code,
                ],
                source: 'reconciliation',
            );

            $this->audit->logSystem(
                'student_package_purchases',
                'package_reconciliation_settlement_failed',
                sprintf('Reconciliation could not settle a provider-confirmed package payment: %s', $e->getMessage()),
                $payment,
                ['payment_id' => $payment->id, 'provider' => $payment->provider],
            );

            return PackageSettlementResult::ignored($payment, null, $e->getMessage());
        }

        if ($result->settled) {
            // PAY-1: the discrepancy is over — close every operational
            // incident this attempt raised, so the queue only ever shows
            // problems that are still real.
            $this->issues->resolveOpenIssuesFor($payment);

            $this->audit->logSystem(
                'student_package_purchases',
                'package_reconciliation_recovered',
                sprintf('Reconciliation recovered and activated package purchase %s.', $result->purchase?->reference),
                $result->purchase,
                ['payment_id' => $payment->id, 'entitlement_id' => $result->entitlement?->id],
            );
        }

        $this->payments->markSynced($payment->refresh());

        return $result;
    }

    /**
     * An authenticated provider fetch — the only thing allowed to
     * assert "this was really paid" outside a signed webhook. Returns
     * null for anything short of an explicit success, including a
     * gateway error: silence is never treated as payment.
     */
    private function providerConfirmsPayment(Payment $payment, bool &$reachable): ?VerifiedPaymentEvent
    {
        $paid = match ($payment->provider) {
            'razorpay' => $this->razorpayOrderIsPaid($payment, $reachable),
            'stripe' => $this->stripeIntentSucceeded($payment, $reachable),
            default => false,
        };

        if (! $paid) {
            return null;
        }

        // Built from our own trusted local snapshot rather than from
        // the fetched body, so the amount/currency checks inside
        // settlement stay meaningful rather than self-confirming.
        return VerifiedPaymentEvent::reconciled(
            provider: (string) $payment->provider,
            type: PaymentEventType::Succeeded,
            reference: $payment->idempotency_key,
            providerOrderId: $payment->provider_order_id,
            providerPaymentId: $payment->provider_payment_id,
            amountMinor: (int) $payment->amount_minor,
            currencyCode: (string) $payment->currency_code,
        );
    }

    /**
     * PAY-1 — an attempt the provider is reachable about but still will
     * not resolve. Two distinct, deterministic conditions, both keyed on
     * observable state and neither inferring anything about the money:
     *
     *   MissingProviderReference — checkout claimed initialization long
     *   ago and never recorded a provider order id, so there is nothing
     *   left to poll. Retrying forever cannot fix it; only a human can.
     *
     *   StaleProcessing — a reference exists and the provider keeps
     *   declining to call it settled, well past the point where that is
     *   normal in-flight behaviour.
     *
     * Both wait OPERATOR_VISIBLE_AFTER_MINUTES so ordinary checkout
     * latency never reaches the queue.
     */
    private function detectStuckAttempt(Payment $payment): void
    {
        $threshold = now()->subMinutes(self::OPERATOR_VISIBLE_AFTER_MINUTES);

        if ($payment->provider_order_id === null) {
            // Only once a claim was actually made — an attempt still
            // awaiting its first initialization is not stuck, it is new.
            $claimedAt = $payment->initialization_claimed_at;

            if ($claimedAt !== null && $claimedAt->lt($threshold)) {
                $this->recordOperationalIssue($payment, PaymentReconciliationIssueType::MissingProviderReference);
            }

            return;
        }

        if ($payment->created_at !== null && $payment->created_at->lt($threshold)) {
            $this->recordOperationalIssue($payment, PaymentReconciliationIssueType::StaleProcessing);
        }
    }

    /**
     * Raises an operational incident, deduplicated by the generic issue
     * service: one open issue per (payment, type), with occurrence_count
     * and last_seen_at advancing on every later sweep. A five-minute
     * scheduler must never mean a five-minute stream of identical rows.
     */
    private function recordOperationalIssue(Payment $payment, PaymentReconciliationIssueType $type): void
    {
        if ($payment->created_at !== null && $payment->created_at->gt(now()->subMinutes(self::OPERATOR_VISIBLE_AFTER_MINUTES))) {
            return;
        }

        $this->issues->record(
            $payment,
            $type,
            [
                'expected_amount_minor' => (int) $payment->amount_minor,
                'expected_currency' => (string) $payment->currency_code,
            ],
            source: 'reconciliation',
        );
    }

    private function razorpayOrderIsPaid(Payment $payment, bool &$reachable): bool
    {
        try {
            $order = $this->razorpay->fetchOrder(
                (string) $this->gatewaySettings->razorpay_key_id,
                (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'razorpay_key_secret'),
                (string) $payment->provider_order_id,
            );
        } catch (GatewayRequestException) {
            // Unreachable, NOT unpaid. The caller needs to tell those
            // apart; silence is still never treated as payment.
            $reachable = false;

            return false;
        }

        return (string) ($order['status'] ?? '') === 'paid';
    }

    private function stripeIntentSucceeded(Payment $payment, bool &$reachable): bool
    {
        try {
            $intent = $this->stripe->retrievePaymentIntent(
                (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'stripe_secret_key'),
                (string) $payment->provider_order_id,
            );
        } catch (GatewayRequestException) {
            $reachable = false;

            return false;
        }

        return (string) ($intent['status'] ?? '') === 'succeeded';
    }
}
