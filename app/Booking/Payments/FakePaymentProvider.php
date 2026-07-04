<?php

declare(strict_types=1);

namespace App\Booking\Payments;

use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentWebhookData;
use App\Booking\Enums\PaymentWebhookEvent;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Models\Booking;
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

        return new PaymentWebhookData(
            event: $event,
            reference: $reference,
            reason: $request->json('reason'),
        );
    }
}
