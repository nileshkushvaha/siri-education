<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\Error;
use Throwable;

/**
 * Wraps the official `razorpay/razorpay` SDK — the only class in this
 * codebase that ever instantiates \Razorpay\Api\Api. Everything above
 * RazorpayPaymentProvider stays SDK-free.
 *
 * fetchOrder() is the exception: it is issued through Laravel's HTTP
 * client rather than the SDK, because the SDK hardcodes a 60-second
 * timeout and this lookup now runs inside a student's own request (the
 * instant confirmation on return from Checkout.js, and every poll tick
 * after it). A gateway slowdown must surface as "unreachable, retry
 * later" in a few seconds — never as a minute-long hang on a page the
 * student is watching.
 */
final class RazorpaySdkClient implements RazorpayGatewayClient
{
    /** Seconds to wait for the order-status endpoint before treating the gateway as unreachable. */
    public const int FETCH_ORDER_TIMEOUT_SECONDS = 8;

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

    public function fetchOrder(string $keyId, string $keySecret, string $orderId): array
    {
        try {
            $response = Http::withBasicAuth($keyId, $keySecret)
                ->acceptJson()
                ->connectTimeout(min(3, self::FETCH_ORDER_TIMEOUT_SECONDS))
                ->timeout(self::FETCH_ORDER_TIMEOUT_SECONDS)
                ->get('https://api.razorpay.com/v1/orders/'.rawurlencode($orderId));
        } catch (ConnectionException $e) {
            throw new GatewayRequestException('Razorpay did not respond in time: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $description = (string) ($response->json('error.description') ?? $response->reason());

            throw new GatewayRequestException('Razorpay order lookup failed: '.$description);
        }

        $order = $response->json();

        if (! is_array($order)) {
            throw new GatewayRequestException('Razorpay order lookup returned an unreadable body.');
        }

        return $order;
    }
}
