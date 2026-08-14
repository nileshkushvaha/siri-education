<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;

/**
 * Phase 4E.2 — a Razorpay double that COUNTS its own order creations
 * across process boundaries.
 *
 * The plain RazorpayConcurrencyFakeClient cannot prove PKG-AUD-004 is
 * closed. The property under test is "the provider was called exactly
 * once", and a client that only mints ids leaves no evidence of a
 * second call: if two workers each created an order, only one id ever
 * reaches `payments.provider_order_id`, so the database looks correct
 * while a second live order exists at the gateway. That is precisely
 * the double-charge the audit found, and it would pass silently.
 *
 * Each createOrder() therefore appends one line to a shared file. The
 * parent test counts the lines. A file is used rather than a static
 * counter or the database because the workers are separate OS
 * processes: no PHP memory is shared, and writing the count to the
 * database under test would pollute the very tables being asserted on.
 *
 * Appends are O_APPEND single-line writes, which are atomic for small
 * buffers on the platforms this harness runs on, so concurrent workers
 * cannot interleave into a corrupt count.
 */
final class CountingRazorpayConcurrencyFakeClient implements RazorpayGatewayClient
{
    public const string LOG_ENV = 'CONCURRENCY_ORDER_LOG';

    public function __construct(
        private readonly ?string $logPath = null,
        /** Simulates a gateway that accepts the order but never answers. */
        private readonly bool $simulateTimeoutOnCreate = false,
    ) {}

    public function createOrder(string $keyId, string $keySecret, array $params): array
    {
        $orderId = 'order_'.bin2hex(random_bytes(8));

        // Recorded BEFORE any simulated failure: an order the provider
        // created but never confirmed to us still counts as a real
        // external order, which is exactly the ambiguity Part 6 is about.
        $this->record($orderId);

        if ($this->simulateTimeoutOnCreate) {
            throw new GatewayRequestException('Simulated gateway timeout.');
        }

        return [
            'id' => $orderId,
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'status' => 'created',
        ];
    }

    public function refundPayment(string $keyId, string $keySecret, string $paymentId, array $params): array
    {
        return ['id' => 'rfnd_'.bin2hex(random_bytes(8)), 'payment_id' => $paymentId, 'amount' => $params['amount'], 'status' => 'processed'];
    }

    public function fetchOrder(string $keyId, string $keySecret, string $orderId): array
    {
        return ['id' => $orderId, 'status' => 'created'];
    }

    private function record(string $orderId): void
    {
        $path = $this->logPath ?? getenv(self::LOG_ENV);

        if (! is_string($path) || $path === '') {
            return;
        }

        file_put_contents($path, $orderId.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
