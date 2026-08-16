<?php

declare(strict_types=1);

namespace App\Booking\Payments;

use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentProviderCapabilities;
use App\Booking\DTOs\PaymentProviderHealth;
use App\Booking\DTOs\PaymentStatusResult;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\Log;

/**
 * Development/default provider: no money moves. Webhooks must still be
 * HMAC-signed (X-Booking-Payment-Signature = sha256 of the raw body
 * with the app key) so the endpoint is never open to forgery, even in
 * non-production environments.
 */
final class FakePaymentProvider implements PaymentProviderInterface
{
    public const string KEY = 'fake';

    public function key(): string
    {
        return self::KEY;
    }

    public function createPayment(Booking $booking, string $reference): PaymentIntentData
    {
        Log::info('FakePaymentProvider: payment created (no gateway).', [
            'booking' => $booking->reference,
            'payment_reference' => $reference,
        ]);

        // Mirrors Razorpay/Stripe's own createPayment(): a Pending row
        // now, captured later — by the webhook settlement path on a 'succeeded'
        // event. Keeps the fake provider's BookingPayment trail
        // consistent with every real adapter (refundToWallet()/
        // refundViaProvider() both require a captured row to exist),
        // rather than a fake-only gap.
        BookingPayment::query()->firstOrCreate(
            ['booking_id' => $booking->id, 'idempotency_key' => $reference],
            [
                'user_id' => $booking->student_id,
                'provider' => self::KEY,
                'amount_minor' => (int) round(((float) $booking->price) * (10 ** MoneyFormatter::minorUnitsFor((string) $booking->currency))),
                'currency_code' => (string) $booking->currency,
                'status' => BookingPaymentRecordStatus::Pending,
                'metadata' => ['fake' => true],
            ],
        );

        return new PaymentIntentData(
            bookingId: $booking->id,
            reference: $reference,
            amount: $booking->price,
            currency: $booking->currency,
            status: 'pending',
            checkoutUrl: null, // a real provider returns its hosted checkout URL
        );
    }

    /** Always "configured" — no credentials exist to validate. */
    public function isConfigured(): bool
    {
        return true;
    }

    /** Accepts any currency — no real gateway constraint applies. */
    public function supportedCurrencies(): array
    {
        return ['INR', 'USD', 'EUR', 'GBP', 'AED'];
    }

    public function checkoutPayload(Booking $booking): array
    {
        return [
            'provider' => self::KEY,
            'reference' => $booking->payment_reference,
            'amount' => $booking->price,
            'currency' => $booking->currency,
        ];
    }

    /** No gateway to poll — reflects back whatever the local row already says. */
    public function fetchStatus(string $providerReference): PaymentStatusResult
    {
        $payment = BookingPayment::query()
            ->where('idempotency_key', $providerReference)
            ->latest('created_at')
            ->first();

        // PAY-1: mirrors the real providers, which report the money
        // they hold alongside the status. A fake that omitted it would
        // exercise a code path production can never take, and would hide
        // the amount/currency check from every test that uses it.
        return new PaymentStatusResult(
            recordStatus: $payment?->status ?? BookingPaymentRecordStatus::Unknown,
            providerPaymentId: $payment?->provider_payment_id,
            providerStatus: $payment?->status->value,
            safeReason: null,
            verifiedAmountMinor: $payment?->amount_minor,
            verifiedCurrency: $payment?->currency_code,
        );
    }

    /** Deliberately unrestricted — exists to prove routing/eligibility works, not to simulate a real provider's actual approvals. */
    public function capabilities(): PaymentProviderCapabilities
    {
        return new PaymentProviderCapabilities(
            provider: self::KEY,
            environment: app()->environment(),
            supportedStudentCountries: [],
            supportedBillingCurrencies: $this->supportedCurrencies(),
            supportedCollectionCurrencies: $this->supportedCurrencies(),
            supportedTransactionTypes: ['wallet_recharge', 'booking_payment', 'recurring_wallet_funding'],
            supportedPaymentMethods: [],
            supportsWalletRecharge: true,
            supportsDirectBookingPayment: true,
            supportsStatusFetch: true,
            supportsWebhooks: true,
            supportsRefunds: true,
            supportsPartialRefunds: false,
            supportsAsyncConfirmation: true,
            supportsIdempotency: true,
            requiresCustomerCreation: false,
            requiresReturnUrl: false,
            requiresWebhookSignature: true,
            healthStatus: new PaymentProviderHealth(healthy: true, safeMessage: 'Fake provider — always healthy, no network dependency.'),
            capabilityVersion: 1,
        );
    }
}
