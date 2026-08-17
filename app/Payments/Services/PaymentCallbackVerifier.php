<?php

declare(strict_types=1);

namespace App\Payments\Services;

use App\Models\Payment;
use App\Payments\Contracts\Payable;
use App\Payments\Exceptions\PaymentException;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;

/**
 * The browser-side checkout callback, for any Payable.
 *
 * DELIBERATELY NON-AUTHORITATIVE. It proves the callback is genuine and
 * that the order belongs to the payable in front of the student, then
 * records WHICH provider payment the browser reported — and nothing
 * more. It never settles an attempt, never touches a domain record, and
 * never triggers a notification or receipt.
 *
 * Two independent reasons it must not settle, both of which hold even
 * for a perfectly honest callback:
 *
 *   1. It is browser-supplied and therefore replayable.
 *   2. Razorpay Checkout.js fires on AUTHORIZATION, not capture. An
 *      authorized-but-uncaptured payment is money SIRI does not have.
 *
 * Settlement belongs entirely to the signed server-to-server webhook
 * (or reconciliation's authenticated provider poll). Recording the
 * payment id here is still worth doing: it correlates the attempt
 * immediately, which is what lets a later webhook that lost its
 * metadata still resolve to the right row.
 *
 * Generic on purpose. The wallet previously carried its own copy of
 * this HMAC check, so the codebase had two implementations of "is this
 * callback real" that could drift apart.
 */
final class PaymentCallbackVerifier
{
    public function __construct(
        private readonly PaymentGatewaySettings $settings,
    ) {}

    /**
     * Verifies a Razorpay Checkout.js success callback and records the
     * reported payment id on the payable's own attempt.
     *
     * The attempt is resolved from the PAYABLE plus the order id, never
     * from the order id alone: that is what stops a valid callback for
     * someone else's order from attaching itself to this payable.
     *
     * @throws PaymentException when the signature is invalid or the order does not belong to this payable
     */
    public function verifyRazorpayCheckout(
        Payable $payable,
        string $orderId,
        string $paymentId,
        string $signature,
    ): Payment {
        $keySecret = (string) PaymentWebhookSignatureService::decryptSecret($this->settings, 'razorpay_key_secret');

        if ($keySecret === '') {
            throw new PaymentException('Payment verification is not available right now.');
        }

        $expected = hash_hmac('sha256', "{$orderId}|{$paymentId}", $keySecret);

        if (! hash_equals($expected, $signature)) {
            throw new PaymentException('Payment verification failed.');
        }

        $attempt = Payment::query()
            ->forPayable($payable->paymentPayableType(), $payable->paymentPayableId())
            ->where('provider', 'razorpay')
            ->where('provider_order_id', $orderId)
            ->first();

        if ($attempt === null) {
            throw new PaymentException('That payment does not belong to this checkout.');
        }

        // Already settled by the webhook (or an earlier callback) — an
        // idempotent no-op. The callback never rewrites a
        // provider-confirmed outcome, in either direction.
        if ($attempt->status->isTerminal()) {
            return $attempt;
        }

        // Never overwrite an id the provider already gave us.
        if ($attempt->provider_payment_id === null) {
            $attempt->forceFill(['provider_payment_id' => $paymentId])->save();
        }

        return $attempt->refresh();
    }
}
