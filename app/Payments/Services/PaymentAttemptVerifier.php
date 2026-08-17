<?php

declare(strict_types=1);

namespace App\Payments\Services;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\Payment;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;

/**
 * Asks a provider whether it actually holds the money for an attempt.
 *
 * The reconciliation counterpart to PaymentWebhookEventParser: a webhook
 * is evidence that arrives, this is evidence we go and fetch. Both
 * produce the same VerifiedPaymentEvent, so every domain settles from
 * one shape of proof regardless of how the proof reached us.
 *
 * One canonical implementation serves every payable. Booking and package
 * reconciliation must not each decide what "Razorpay says paid" means —
 * that is precisely the kind of divergence that lets two domains
 * disagree about whether money exists.
 *
 * Two rules this class exists to keep:
 *
 *   Silence is never payment. A provider we cannot reach reports
 *   `$reachable = false`, which is categorically different from a
 *   provider that answered "not paid". Callers must tell them apart:
 *   one is an outage, the other is a fact.
 *
 *   The event carries what the PROVIDER reported, not what we stored.
 *   This class previously rebuilt the event from the attempt's own
 *   amount and currency, on the stated reasoning that echoing the
 *   provider's figures back would make settlement's checks
 *   self-confirming. That reasoning is inverted: settlement compares
 *   the event against the attempt, so feeding it the attempt's own
 *   values compared the row with itself and the mismatch guards could
 *   never fire on the reconciliation path at all. Feeding it the
 *   provider's values is what makes those guards mean anything.
 *
 *   A provider that confirms payment without reporting an amount or
 *   currency yields nulls, which settlement treats as "unproven" and
 *   skips — never as agreement.
 */
final class PaymentAttemptVerifier
{
    public function __construct(
        private readonly RazorpayGatewayClient $razorpay,
        private readonly StripeGatewayClient $stripe,
        private readonly PaymentGatewaySettings $gatewaySettings,
    ) {}

    /**
     * @param  bool  $reachable  set to false when the provider could not be contacted
     * @return VerifiedPaymentEvent|null the proof of payment, or null when the provider does not confirm one
     */
    public function confirmedPayment(Payment $payment, bool &$reachable): ?VerifiedPaymentEvent
    {
        if ($payment->provider_order_id === null) {
            return null;
        }

        $confirmed = match ($payment->provider) {
            'razorpay' => $this->razorpayOrderIsPaid($payment, $reachable),
            'stripe' => $this->stripeIntentSucceeded($payment, $reachable),
            default => null,
        };

        if ($confirmed === null) {
            return null;
        }

        return VerifiedPaymentEvent::reconciled(
            provider: (string) $payment->provider,
            type: PaymentEventType::Succeeded,
            reference: $payment->idempotency_key,
            providerOrderId: $payment->provider_order_id,
            providerPaymentId: $payment->provider_payment_id,
            amountMinor: $confirmed['amountMinor'],
            currencyCode: $confirmed['currencyCode'],
        );
    }

    /**
     * What the provider says it collected, or null when it does not
     * confirm a payment at all.
     *
     * @return array{amountMinor: ?int, currencyCode: ?string}|null
     */
    private function razorpayOrderIsPaid(Payment $payment, bool &$reachable): ?array
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

            return null;
        }

        if ((string) ($order['status'] ?? '') !== 'paid') {
            return null;
        }

        // The order body carries both figures; discarding them is what
        // let reconciliation settle without checking what was collected.
        return [
            'amountMinor' => isset($order['amount']) ? (int) $order['amount'] : null,
            'currencyCode' => isset($order['currency']) ? strtoupper((string) $order['currency']) : null,
        ];
    }

    /** @return array{amountMinor: ?int, currencyCode: ?string}|null */
    private function stripeIntentSucceeded(Payment $payment, bool &$reachable): ?array
    {
        try {
            $intent = $this->stripe->retrievePaymentIntent(
                (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'stripe_secret_key'),
                (string) $payment->provider_order_id,
            );
        } catch (GatewayRequestException) {
            $reachable = false;

            return null;
        }

        if ((string) ($intent['status'] ?? '') !== 'succeeded') {
            return null;
        }

        // `amount_received` is Stripe's authoritative captured amount;
        // `amount` is only what was requested.
        return [
            'amountMinor' => isset($intent['amount_received']) ? (int) $intent['amount_received'] : null,
            'currencyCode' => isset($intent['currency']) ? strtoupper((string) $intent['currency']) : null,
        ];
    }
}
