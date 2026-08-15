<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Settings\PaymentGatewaySettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PaymentGatewayConnectionService
{
    /**
     * @param  array<string, mixed>  $runtimeData
     */
    public function test(string $gateway, array $runtimeData, PaymentGatewaySettings $settings): GatewayConnectionResult
    {
        return match ($gateway) {
            'stripe' => $this->testStripe($runtimeData, $settings),
            'razorpay' => $this->testRazorpay($runtimeData, $settings),
            'paypal' => $this->testPayPal($runtimeData, $settings),
            'manual' => new GatewayConnectionResult(true, 'Manual payment does not require API connectivity.'),
            default => new GatewayConnectionResult(false, "Unsupported gateway '{$gateway}'."),
        };
    }

    /**
     * @param  array<string, mixed>  $runtimeData
     */
    private function testStripe(array $runtimeData, PaymentGatewaySettings $settings): GatewayConnectionResult
    {
        $secret = $this->resolveSecret($runtimeData['stripe_secret_key'] ?? null, $settings->stripe_secret_key);

        if (blank($secret)) {
            return new GatewayConnectionResult(false, 'Stripe secret key is missing.');
        }

        try {
            $response = Http::timeout(15)
                ->withToken($secret)
                ->acceptJson()
                ->get('https://api.stripe.com/v1/account');

            if (! $response->successful()) {
                return new GatewayConnectionResult(false, 'Stripe API rejected credentials.', [
                    'http_status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }

            return new GatewayConnectionResult(true, 'Stripe connection successful.', [
                'account_id' => $response->json('id'),
                'country' => $response->json('country'),
            ]);
        } catch (Throwable $e) {
            return new GatewayConnectionResult(false, "Stripe connection failed: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $runtimeData
     */
    private function testRazorpay(array $runtimeData, PaymentGatewaySettings $settings): GatewayConnectionResult
    {
        $keyId = $runtimeData['razorpay_key_id'] ?? $settings->razorpay_key_id;
        $secret = $this->resolveSecret($runtimeData['razorpay_key_secret'] ?? null, $settings->razorpay_key_secret);

        if (blank($keyId) || blank($secret)) {
            return new GatewayConnectionResult(false, 'Razorpay Key ID or Key Secret is missing.');
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth((string) $keyId, (string) $secret)
                ->acceptJson()
                ->get('https://api.razorpay.com/v1/items', ['count' => 1]);

            if (! $response->successful()) {
                return new GatewayConnectionResult(false, 'Razorpay API rejected credentials.', [
                    'http_status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }

            return new GatewayConnectionResult(true, 'Razorpay connection successful.');
        } catch (Throwable $e) {
            return new GatewayConnectionResult(false, "Razorpay connection failed: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $runtimeData
     */
    private function testPayPal(array $runtimeData, PaymentGatewaySettings $settings): GatewayConnectionResult
    {
        $clientId = $runtimeData['paypal_client_id'] ?? $settings->paypal_client_id;
        $secret = $this->resolveSecret($runtimeData['paypal_client_secret'] ?? null, $settings->paypal_client_secret);
        $mode = $runtimeData['paypal_mode'] ?? $settings->paypal_mode;

        if (blank($clientId) || blank($secret)) {
            return new GatewayConnectionResult(false, 'PayPal Client ID or Client Secret is missing.');
        }

        $baseUrl = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        try {
            $response = Http::timeout(20)
                ->asForm()
                ->withBasicAuth((string) $clientId, (string) $secret)
                ->post("{$baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if (! $response->successful()) {
                return new GatewayConnectionResult(false, 'PayPal API rejected credentials.', [
                    'http_status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }

            return new GatewayConnectionResult(true, 'PayPal connection successful.', [
                'token_type' => $response->json('token_type'),
                'expires_in' => $response->json('expires_in'),
            ]);
        } catch (Throwable $e) {
            return new GatewayConnectionResult(false, "PayPal connection failed: {$e->getMessage()}");
        }
    }

    private function resolveSecret(?string $runtimeValue, ?string $storedEncryptedValue): ?string
    {
        if (filled($runtimeValue)) {
            return $runtimeValue;
        }

        if (blank($storedEncryptedValue)) {
            return null;
        }

        try {
            return Crypt::decryptString($storedEncryptedValue);
        } catch (Throwable) {
            return null;
        }
    }
}
