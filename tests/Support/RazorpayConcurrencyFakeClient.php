<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Booking\Contracts\RazorpayGatewayClient;

/**
 * A deterministic, network-free double for the real-MySQL concurrency
 * harness (tests/Concurrency/run-op.php) — child worker processes
 * cannot share a Mockery instance across process boundaries, mirroring
 * Tests\Support\StripeConcurrencyFakeClient's role on the Stripe side.
 * Every order identifier is freshly minted per call; the concurrency
 * property under test is that the DATABASE layer (unique constraint +
 * row lock) is what prevents a double-settle, never this client.
 */
final class RazorpayConcurrencyFakeClient implements RazorpayGatewayClient
{
    public function createOrder(string $keyId, string $keySecret, array $params): array
    {
        return [
            'id' => 'order_'.bin2hex(random_bytes(8)),
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'status' => 'created',
        ];
    }

    public function refundPayment(string $keyId, string $keySecret, string $paymentId, array $params): array
    {
        return [
            'id' => 'rfnd_'.bin2hex(random_bytes(8)),
            'payment_id' => $paymentId,
            'amount' => $params['amount'],
            'status' => 'processed',
        ];
    }

    public function fetchOrder(string $keyId, string $keySecret, string $orderId): array
    {
        return [
            'id' => $orderId,
            'status' => 'paid',
        ];
    }
}
