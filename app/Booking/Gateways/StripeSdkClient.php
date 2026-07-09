<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;

/**
 * Wraps the official `stripe/stripe-php` SDK — the only class in this
 * codebase that ever instantiates \Stripe\StripeClient. Everything
 * above StripePaymentProvider stays SDK-free.
 */
final class StripeSdkClient implements StripeGatewayClient
{
    public function createPaymentIntent(string $secretKey, array $params, string $idempotencyKey): array
    {
        try {
            $client = new StripeClient($secretKey);

            return $client->paymentIntents->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
        } catch (ApiErrorException|Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function retrievePaymentIntent(string $secretKey, string $paymentIntentId): array
    {
        try {
            $client = new StripeClient($secretKey);

            return $client->paymentIntents->retrieve($paymentIntentId)->toArray();
        } catch (ApiErrorException|Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function createRefund(string $secretKey, array $params): array
    {
        try {
            $client = new StripeClient($secretKey);

            return $client->refunds->create($params)->toArray();
        } catch (ApiErrorException|Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }
}
