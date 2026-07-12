<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\Exceptions\GatewayRequestException;

/**
 * The only seam through which RazorpayPaymentProvider talks to the
 * `razorpay/razorpay` SDK. Credentials are passed per-call (not bound
 * at construction) because they come from PaymentGatewaySettings at
 * the moment of use, not from container-time configuration — the
 * active key_id/key_secret can change between requests if an admin
 * updates settings. Tests bind a fake implementation of this interface
 * instead of stubbing HTTP/cURL.
 */
interface RazorpayGatewayClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws GatewayRequestException
     */
    public function createOrder(string $keyId, string $keySecret, array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws GatewayRequestException
     */
    public function refundPayment(string $keyId, string $keySecret, string $paymentId, array $params): array;

    /**
     * Fetches the order (not a specific payment) — reconciliation's
     * `$providerReference` is always `booking_payments.provider_order_id`,
     * the value known from the moment `createPayment()` runs, not the
     * payment ID (only assigned once a payment settles).
     *
     * @return array<string, mixed>
     *
     * @throws GatewayRequestException
     */
    public function fetchOrder(string $keyId, string $keySecret, string $orderId): array;
}
