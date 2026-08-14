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

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PackagePurchaseSettlementService $settlement,
        private readonly RazorpayGatewayClient $razorpay,
        private readonly StripeGatewayClient $stripe,
        private readonly PaymentGatewaySettings $gatewaySettings,
        private readonly AuditTrailService $audit,
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
            $this->payments->markSynced($payment);

            return PackageSettlementResult::ignored($payment, null, 'Nothing to reconcile.');
        }

        $confirmed = $this->providerConfirmsPayment($payment);

        if ($confirmed === null) {
            $this->payments->markSynced($payment);

            return PackageSettlementResult::ignored($payment, null, 'The provider has not confirmed this payment.');
        }

        try {
            $result = $this->settlement->settle($payment, $confirmed);
        } catch (PackageException $e) {
            // Left open on purpose: the next sweep retries. This is the
            // recovery path for "money collected, activation failed".
            $this->payments->markSynced($payment);

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
    private function providerConfirmsPayment(Payment $payment): ?VerifiedPaymentEvent
    {
        $paid = match ($payment->provider) {
            'razorpay' => $this->razorpayOrderIsPaid($payment),
            'stripe' => $this->stripeIntentSucceeded($payment),
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

    private function razorpayOrderIsPaid(Payment $payment): bool
    {
        try {
            $order = $this->razorpay->fetchOrder(
                (string) $this->gatewaySettings->razorpay_key_id,
                (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'razorpay_key_secret'),
                (string) $payment->provider_order_id,
            );
        } catch (GatewayRequestException) {
            return false;
        }

        return (string) ($order['status'] ?? '') === 'paid';
    }

    private function stripeIntentSucceeded(Payment $payment): bool
    {
        try {
            $intent = $this->stripe->retrievePaymentIntent(
                (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'stripe_secret_key'),
                (string) $payment->provider_order_id,
            );
        } catch (GatewayRequestException) {
            return false;
        }

        return (string) ($intent['status'] ?? '') === 'succeeded';
    }
}
