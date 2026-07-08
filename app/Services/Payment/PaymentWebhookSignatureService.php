<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Settings\PaymentGatewaySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

final class PaymentWebhookSignatureService
{
    public function isValid(string $gateway, Request $request, PaymentGatewaySettings $settings): bool
    {
        $secret = $this->decryptSecret($settings, "{$gateway}_webhook_secret");

        if (blank($secret)) {
            // Allow if no secret configured (safe default for setup phase)
            return true;
        }

        $payload = (string) $request->getContent();

        return match ($gateway) {
            'stripe' => $this->verifyStripe($request->header('Stripe-Signature'), $payload, $secret),
            'razorpay' => $this->verifyHmacHeader($request->header('X-Razorpay-Signature'), $payload, $secret),
            'cashfree' => $this->verifyHmacHeader($request->header('x-webhook-signature'), $payload, $secret),
            default => true,
        };
    }

    private function verifyStripe(?string $signatureHeader, string $payload, string $secret): bool
    {
        if (blank($signatureHeader)) {
            return false;
        }

        $parts = collect(explode(',', $signatureHeader))
            ->map(fn (string $part): array => explode('=', $part, 2))
            ->filter(fn (array $part): bool => count($part) === 2)
            ->mapWithKeys(fn (array $part): array => [trim($part[0]) => trim($part[1])]);

        $timestamp = $parts->get('t');
        $signature = $parts->get('v1');

        if (blank($timestamp) || blank($signature)) {
            return false;
        }

        $signedPayload = "{$timestamp}.{$payload}";
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, (string) $signature);
    }

    private function verifyHmacHeader(?string $providedSignature, string $payload, string $secret): bool
    {
        if (blank($providedSignature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, (string) $providedSignature);
    }

    /** Shared with RazorpayPaymentProvider — one decrypt-with-legacy-fallback routine for all gateway secrets. */
    public static function decryptSecret(PaymentGatewaySettings $settings, string $field): ?string
    {
        $value = $settings->{$field} ?? null;

        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Gracefully handle already-plain values from old installs.
            return Str::startsWith((string) $value, 'eyJpdiI6') ? null : (string) $value;
        }
    }
}
