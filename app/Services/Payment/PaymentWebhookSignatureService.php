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
    /** Gateways with a real signature-verification implementation below — a blank secret must fail closed for these. */
    private const array VERIFIABLE_GATEWAYS = ['stripe', 'razorpay'];

    /** Endpoint purposes that own their own webhook secrets. */
    public const string PURPOSE_BOOKING = 'booking';

    public const string PURPOSE_PACKAGE = 'package';

    /**
     * Wallet recharge. A distinct endpoint from booking and package
     * collection, so it gets a distinct scope: a leaked recharge secret
     * must not become authority to settle lessons, and vice versa.
     */
    public const string PURPOSE_WALLET = 'wallet';

    /**
     * @param  string|null  $purpose  which endpoint received this delivery
     *                                (self::PURPOSE_*), so a secret issued for one endpoint
     *                                cannot authenticate another. Null means "any purpose"
     *                                and should only be used by callers with no endpoint
     *                                identity of their own.
     */
    public function isValid(string $gateway, Request $request, PaymentGatewaySettings $settings, ?string $purpose = null): bool
    {
        // The local/testing provider signs with the app key. It has no
        // gateway-issued secret, but it must still be verified: an
        // unsigned settlement path is a settlement path an attacker can
        // use, and the retired Booking provider did check this.
        if ($gateway === 'fake') {
            $header = (string) $request->header('X-Booking-Payment-Signature', '');

            return $header !== '' && hash_equals(
                hash_hmac('sha256', (string) $request->getContent(), (string) config('app.key')),
                $header,
            );
        }

        // A gateway account can legitimately have MORE THAN ONE webhook
        // endpoint, each issued its own secret — this platform registers
        // separate Razorpay endpoints for booking payments and package
        // purchases. Secrets are therefore scoped to the endpoint that
        // received the delivery: a booking secret must NOT be able to
        // authenticate a package webhook, or a leak of either one would
        // silently become authority over both.
        $secrets = self::decryptSecrets($settings, "{$gateway}_webhook_secret", $purpose);

        if ($secrets === []) {
            // A blank secret used to fail OPEN ("safe
            // default while unconfigured") — accepting an entirely unsigned
            // request. It now fails closed for every gateway this class
            // actually knows how to verify; only a gateway with no
            // verification implemented at all (paypal/applepay — no
            // real adapter exists yet — and manual, which by definition
            // has no signature) still passes through unsigned.
            return ! in_array($gateway, self::VERIFIABLE_GATEWAYS, true);
        }

        $payload = (string) $request->getContent();

        // Every candidate is checked with a constant-time comparison and
        // the loop is NOT short-circuited on a non-match, so which
        // secret matched (and how many are configured) is not leaked
        // through response timing.
        $valid = false;

        foreach ($secrets as $secret) {
            $matches = match ($gateway) {
                'stripe' => $this->verifyStripe($request->header('Stripe-Signature'), $payload, $secret),
                'razorpay' => $this->verifyHmacHeader($request->header('X-Razorpay-Signature'), $payload, $secret),
                default => true,
            };

            $valid = $valid || $matches;
        }

        return $valid;
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

    /**
     * The webhook secrets configured for a gateway, scoped to one
     * endpoint purpose.
     *
     * The stored field holds ONE SECRET PER LINE. A line may name the
     * endpoint it belongs to:
     *
     *     booking:whsec_aaa      <- only the booking endpoint
     *     package:whsec_bbb      <- only the package endpoint
     *     whsec_ccc              <- unscoped (legacy)
     *
     * Two lines with the SAME prefix is the credential-rotation case:
     * old and new are both live while the provider is switched over.
     *
     * Unprefixed lines stay valid for every purpose, which is what
     * keeps existing single-secret installs working untouched — no
     * migration, and nobody has to re-enter a secret. Adding a prefixed
     * line is how an operator opts that endpoint into isolation; once
     * an endpoint has its own prefixed secrets, a secret prefixed for a
     * DIFFERENT endpoint can never authenticate it.
     *
     * @param  string|null  $purpose  self::PURPOSE_*, or null to accept every scope
     * @return list<string>
     */
    public static function decryptSecrets(PaymentGatewaySettings $settings, string $field, ?string $purpose = null): array
    {
        $value = self::decryptSecret($settings, $field);

        if (blank($value)) {
            return [];
        }

        $known = [self::PURPOSE_BOOKING, self::PURPOSE_PACKAGE, self::PURPOSE_WALLET];

        return collect(preg_split('/\R/', $value) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->map(function (string $line) use ($known): array {
                // Only a RECOGNISED prefix scopes a line. Anything else
                // is treated as part of the secret itself, so a secret
                // that happens to contain a colon is never truncated.
                $scope = Str::lower(Str::before($line, ':'));

                return in_array($scope, $known, true) && Str::contains($line, ':')
                    ? ['scope' => $scope, 'secret' => trim(Str::after($line, ':'))]
                    : ['scope' => null, 'secret' => $line];
            })
            ->filter(fn (array $entry): bool => $entry['secret'] !== '')
            ->filter(fn (array $entry): bool => $purpose === null
                || $entry['scope'] === null
                || $entry['scope'] === $purpose)
            ->pluck('secret')
            ->unique()
            ->values()
            ->all();
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
