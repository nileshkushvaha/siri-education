<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\Exceptions\GatewayRequestException;

/**
 * The only seam through which StripePaymentProvider talks to the
 * `stripe/stripe-php` SDK. See RazorpayGatewayClient for why
 * credentials are per-call arguments rather than constructor state.
 */
interface StripeGatewayClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws GatewayRequestException
     */
    public function createPaymentIntent(string $secretKey, array $params, string $idempotencyKey): array;

    /**
     * @return array<string, mixed>
     *
     * @throws GatewayRequestException
     */
    public function retrievePaymentIntent(string $secretKey, string $paymentIntentId): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws GatewayRequestException
     */
    public function createRefund(string $secretKey, array $params): array;
}
