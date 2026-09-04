<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Throwable;

/**
 * Wraps the official `stripe/stripe-php` SDK — the only class in this
 * codebase that ever instantiates \Stripe\StripeClient. Everything
 * above StripePaymentProvider stays SDK-free.
 *
 * Timeouts: the SDK defaults to 80s request / 30s connect. Payment-intent
 * retrieval now runs inside a student's own request (instant recharge
 * confirmation and every poll tick after it), so a gateway slowdown must
 * surface as "unreachable, retry later" in seconds, not hang the page.
 */
final class StripeSdkClient implements StripeGatewayClient
{
    public const int REQUEST_TIMEOUT_SECONDS = 15;

    public const int CONNECT_TIMEOUT_SECONDS = 5;

    private static bool $timeoutsApplied = false;

    private function client(string $secretKey): StripeClient
    {
        if (! self::$timeoutsApplied) {
            $curl = new CurlClient;
            $curl->setTimeout(self::REQUEST_TIMEOUT_SECONDS);
            $curl->setConnectTimeout(self::CONNECT_TIMEOUT_SECONDS);
            ApiRequestor::setHttpClient($curl);
            self::$timeoutsApplied = true;
        }

        return new StripeClient($secretKey);
    }

    public function createPaymentIntent(string $secretKey, array $params, string $idempotencyKey): array
    {
        try {
            $client = $this->client($secretKey);

            return $client->paymentIntents->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
        } catch (ApiErrorException|Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function retrievePaymentIntent(string $secretKey, string $paymentIntentId): array
    {
        try {
            $client = $this->client($secretKey);

            return $client->paymentIntents->retrieve($paymentIntentId)->toArray();
        } catch (ApiErrorException|Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function createRefund(string $secretKey, array $params): array
    {
        try {
            $client = $this->client($secretKey);

            return $client->refunds->create($params)->toArray();
        } catch (ApiErrorException|Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }
}
