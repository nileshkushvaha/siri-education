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
 *   The event is built from our own stored snapshot, never from the
 *   fetched body. If it echoed the provider's amount back, the
 *   amount/currency checks performed during settlement would be
 *   self-confirming and could never catch a mismatch.
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

        $paid = match ($payment->provider) {
            'razorpay' => $this->razorpayOrderIsPaid($payment, $reachable),
            'stripe' => $this->stripeIntentSucceeded($payment, $reachable),
            default => false,
        };

        if (! $paid) {
            return null;
        }

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
