<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Gateways\RazorpaySdkClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * fetchOrder() bypasses the SDK's hardcoded 60s timeout because it now
 * runs inside the student's own request. These pin the contract the
 * PaymentAttemptVerifier relies on: a readable order body on success,
 * GatewayRequestException (never a raw HTTP exception, never a fake
 * "not paid") on anything else.
 */
final class RazorpaySdkClientFetchOrderTest extends TestCase
{
    public function test_returns_the_order_body_and_authenticates_with_the_key_pair(): void
    {
        Http::fake(['api.razorpay.com/v1/orders/order_1' => Http::response(['id' => 'order_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'INR'])]);

        $order = (new RazorpaySdkClient)->fetchOrder('rzp_key', 'rzp_secret', 'order_1');

        $this->assertSame('paid', $order['status']);
        $this->assertSame(50000, $order['amount']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.razorpay.com/v1/orders/order_1'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('rzp_key:rzp_secret')));
    }

    public function test_a_timeout_becomes_a_gateway_request_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(GatewayRequestException::class);
        $this->expectExceptionMessage('did not respond in time');

        (new RazorpaySdkClient)->fetchOrder('rzp_key', 'rzp_secret', 'order_slow');
    }

    public function test_a_gateway_error_response_becomes_a_gateway_request_exception(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['error' => ['description' => 'The id provided does not exist']], 400)]);

        $this->expectException(GatewayRequestException::class);
        $this->expectExceptionMessage('does not exist');

        (new RazorpaySdkClient)->fetchOrder('rzp_key', 'rzp_secret', 'order_missing');
    }

    public function test_the_request_carries_a_short_timeout(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_2', 'status' => 'created'])]);

        (new RazorpaySdkClient)->fetchOrder('rzp_key', 'rzp_secret', 'order_2');

        $this->assertLessThanOrEqual(10, RazorpaySdkClient::FETCH_ORDER_TIMEOUT_SECONDS);
    }
}
