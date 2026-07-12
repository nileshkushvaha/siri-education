<?php

declare(strict_types=1);

namespace App\Booking\Payments;

use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentProviderCapabilities;
use App\Booking\DTOs\PaymentProviderHealth;
use App\Booking\DTOs\PaymentStatusResult;
use App\Booking\DTOs\PaymentWebhookData;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\PaymentWebhookEvent;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
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
        // now, captured later — here, by parseWebhook() on a 'succeeded'
        // event. Keeps the fake provider's BookingPayment trail
        // consistent with every real adapter (refundToWallet()/
        // refundViaProvider() both require a captured row to exist),
        // rather than a fake-only gap.
        BookingPayment::query()->firstOrCreate(
            ['booking_id' => $booking->id, 'idempotency_key' => $reference],
            [
                'user_id' => $booking->attendee_id,
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

    public function refund(Booking $booking): void
    {
        Log::info('FakePaymentProvider: refund issued (no gateway).', [
            'booking' => $booking->reference,
            'payment_reference' => $booking->payment_reference,
        ]);
    }

    public function parseWebhook(Request $request): PaymentWebhookData
    {
        $signature = (string) $request->header('X-Booking-Payment-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), (string) config('app.key'));

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new InvalidPaymentWebhookException('Webhook signature verification failed.');
        }

        $event = PaymentWebhookEvent::tryFrom((string) $request->json('event'));
        $reference = (string) $request->json('reference');

        if ($event === null || $reference === '') {
            throw new InvalidPaymentWebhookException('Webhook payload is malformed.');
        }

        if ($event === PaymentWebhookEvent::Succeeded) {
            BookingPayment::query()
                ->where('idempotency_key', $reference)
                ->where('status', BookingPaymentRecordStatus::Pending)
                ->update(['status' => BookingPaymentRecordStatus::Captured]);
        } elseif ($event === PaymentWebhookEvent::Failed) {
            BookingPayment::query()
                ->where('idempotency_key', $reference)
                ->where('status', BookingPaymentRecordStatus::Pending)
                ->update(['status' => BookingPaymentRecordStatus::Failed]);
        }

        return new PaymentWebhookData(
            event: $event,
            reference: $reference,
            reason: $request->json('reason'),
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

        return new PaymentStatusResult(
            recordStatus: $payment?->status ?? BookingPaymentRecordStatus::Unknown,
            providerPaymentId: $payment?->provider_payment_id,
            providerStatus: $payment?->status->value,
            safeReason: null,
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
