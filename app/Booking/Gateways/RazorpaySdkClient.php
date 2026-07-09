<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\Error;
use Throwable;

/**
 * Wraps the official `razorpay/razorpay` SDK — the only class in this
 * codebase that ever instantiates \Razorpay\Api\Api. Everything above
 * RazorpayPaymentProvider stays SDK-free.
 */
final class RazorpaySdkClient implements RazorpayGatewayClient
{
    public function createOrder(string $keyId, string $keySecret, array $params): array
    {
        try {
            $api = new Api($keyId, $keySecret);

            return $api->order->create($params)->toArray();
        } catch (Error|Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function refundPayment(string $keyId, string $keySecret, string $paymentId, array $params): array
    {
        try {
            $api = new Api($keyId, $keySecret);

            return $api->payment->fetch($paymentId)->refund($params)->toArray();
        } catch (Error|Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }
}
