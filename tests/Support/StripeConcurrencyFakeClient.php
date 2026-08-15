<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\BookingPayment;

/**
 * A deterministic, network-free double for the real-MySQL concurrency
 * harness (tests/Concurrency/run-op.php) — child worker processes
 * cannot share a Mockery instance across process boundaries, mirroring
 * Tests\Support\RazorpayXConcurrencyFakeClient's role on the payout
 * side. Every intent/refund identifier is freshly minted per call; the
 * concurrency property under test is that the DATABASE layer (unique
 * constraint + row lock on booking_payments) allows only one accepted
 * payment intent per booking payment attempt, never this client's
 * behavior.
 */
final class StripeConcurrencyFakeClient implements StripeGatewayClient
{
    public function __construct(
        private readonly bool $simulateTimeoutOnCreate = false,
        /** Explicit override for wallet-recharge races. When null, retrievePaymentIntent() mirrors the matching BookingPayment, as a real intent would. */
        private readonly ?int $retrieveAmountReceived = null,
        private readonly ?string $retrieveCurrency = null,
    ) {}

    public function createPaymentIntent(string $secretKey, array $params, string $idempotencyKey): array
    {
        if ($this->simulateTimeoutOnCreate) {
            // Simulates the primary provider timing out before acceptance
            // — the safe-fallback rule (§9 of the routing audit) only
            // permits a retry/fallback path *before* this point; this
            // proves a timed-out leg never produces an accepted intent.
            throw new GatewayRequestException('Simulated gateway timeout.');
        }

        $id = 'pi_'.bin2hex(random_bytes(8));

        return [
            'id' => $id,
            'client_secret' => $id.'_secret_'.bin2hex(random_bytes(4)),
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'status' => 'requires_payment_method',
        ];
    }

    public function retrievePaymentIntent(string $secretKey, string $paymentIntentId): array
    {
        // PAY-1: a real Stripe intent ALWAYS reports the money it took,
        // and booking settlement now compares it against the booking
        // payment before capturing (PAY-AUD-005). An explicit override
        // still wins — wallet-recharge races set it deliberately — but
        // the default is no longer "omit the fields", because that made
        // the fake describe a response Stripe never sends.
        $amountReceived = $this->retrieveAmountReceived;
        $currency = $this->retrieveCurrency;

        if ($amountReceived === null || $currency === null) {
            $payment = BookingPayment::query()->where('provider_order_id', $paymentIntentId)->first();

            $amountReceived ??= $payment?->amount_minor;
            $currency ??= $payment !== null ? strtolower((string) $payment->currency_code) : null;
        }

        return array_filter([
            'id' => $paymentIntentId,
            'client_secret' => $paymentIntentId.'_secret_retrieved',
            'status' => 'succeeded',
            'amount_received' => $amountReceived,
            'currency' => $currency,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function createRefund(string $secretKey, array $params): array
    {
        return [
            'id' => 're_'.bin2hex(random_bytes(8)),
            'payment_intent' => $params['payment_intent'],
            'amount' => $params['amount'],
            'status' => 'succeeded',
        ];
    }
}
