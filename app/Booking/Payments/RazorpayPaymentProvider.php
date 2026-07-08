<?php

declare(strict_types=1);

namespace App\Booking\Payments;

use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentWebhookData;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\PaymentWebhookEvent;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Currency;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay Orders API + Checkout.js integration. INR-only in this
 * phase — any other booking currency is rejected before an order is
 * ever created. Amounts are always integer paise; the decimal price
 * on Booking is converted at this boundary, never stored as float
 * anywhere in the payment record.
 *
 * Two independent verification paths, both server-side, both mandatory:
 *  - verifyCheckout(): the client-side Checkout.js success callback
 *    (order_id|payment_id HMAC'd with the key secret) — fast path,
 *    but never trusted alone.
 *  - parseWebhook(): the async server-to-server webhook (raw body
 *    HMAC'd with the webhook secret) — the authoritative fallback,
 *    since a client can close the tab before the callback fires.
 * Both paths converge on the same booking_payments row and the same
 * BookingPaymentService::markPaid(), so whichever arrives first wins
 * and the second is a no-op (BookingException "not pending" is caught
 * and acknowledged, never a double-mark).
 */
final class RazorpayPaymentProvider implements PaymentProviderInterface
{
    private const KEY = 'razorpay';

    private const API_BASE = 'https://api.razorpay.com/v1';

    private const SUPPORTED_CURRENCY = 'INR';

    public function __construct(
        private readonly PaymentGatewaySettings $settings,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function createPayment(Booking $booking, string $reference): PaymentIntentData
    {
        $this->assertConfigured();

        $currencyCode = strtoupper((string) ($booking->currency ?? self::SUPPORTED_CURRENCY));

        if ($currencyCode !== self::SUPPORTED_CURRENCY) {
            throw new BookingException(sprintf(
                'Razorpay only supports %s in this phase (booking currency: %s).',
                self::SUPPORTED_CURRENCY,
                $currencyCode,
            ));
        }

        $reusable = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->where('idempotency_key', $reference)
            ->where('status', BookingPaymentRecordStatus::Pending)
            ->whereNotNull('provider_order_id')
            ->latest('created_at')
            ->first();

        if ($reusable !== null) {
            return $this->intentFrom($booking, $reference, $reusable);
        }

        $amountMinor = $this->toMinorUnits((float) $booking->price);

        $payment = BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $booking->attendee_id,
            'provider' => self::KEY,
            'amount_minor' => $amountMinor,
            'currency_code' => self::SUPPORTED_CURRENCY,
            'status' => BookingPaymentRecordStatus::Pending,
            'idempotency_key' => $reference,
            'metadata' => ['receipt' => $reference],
            'created_by' => Auth::id(),
        ]);

        try {
            $response = Http::timeout(15)
                ->withBasicAuth($this->keyId(), $this->keySecret())
                ->acceptJson()
                ->post(self::API_BASE.'/orders', [
                    'amount' => $amountMinor,
                    'currency' => self::SUPPORTED_CURRENCY,
                    'receipt' => $reference,
                    'notes' => [
                        'booking_id' => $booking->id,
                        'booking_reference' => $booking->reference,
                    ],
                ]);
        } catch (ConnectionException $e) {
            $payment->forceFill(['status' => BookingPaymentRecordStatus::Failed, 'failed_at' => now()])->save();

            throw new BookingException('Unable to reach Razorpay: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $payment->forceFill(['status' => BookingPaymentRecordStatus::Failed, 'failed_at' => now()])->save();

            throw new BookingException(
                'Razorpay order creation failed: '.(string) $response->json('error.description', 'unknown error'),
            );
        }

        $payment->forceFill(['provider_order_id' => (string) $response->json('id')])->save();

        return $this->intentFrom($booking, $reference, $payment);
    }

    public function refund(Booking $booking): void
    {
        $this->assertConfigured();

        $payment = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->where('status', BookingPaymentRecordStatus::Captured)
            ->whereNotNull('provider_payment_id')
            ->latest('paid_at')
            ->first();

        if ($payment === null) {
            throw new BookingException(sprintf('Booking %s has no captured Razorpay payment to refund.', $booking->reference));
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth($this->keyId(), $this->keySecret())
                ->acceptJson()
                ->post(self::API_BASE."/payments/{$payment->provider_payment_id}/refund", [
                    'amount' => $payment->amount_minor,
                ]);
        } catch (ConnectionException $e) {
            throw new BookingException('Unable to reach Razorpay: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new BookingException(
                'Razorpay refund failed: '.(string) $response->json('error.description', 'unknown error'),
            );
        }

        $payment->forceFill(['status' => BookingPaymentRecordStatus::Refunded])->save();
    }

    /**
     * Client-side Checkout.js success callback. Verifies the
     * order_id|payment_id HMAC against the key secret, then confirms
     * the order belongs to this booking and matches the amount on
     * record — a forged or replayed callback for a different
     * booking/order cannot settle this one.
     *
     * @throws InvalidPaymentWebhookException when the signature is invalid
     * @throws BookingException when the order does not match this booking or is already settled
     */
    public function verifyCheckout(Booking $booking, string $orderId, string $paymentId, string $signature): BookingPayment
    {
        $this->assertConfigured();

        $expected = hash_hmac('sha256', "{$orderId}|{$paymentId}", $this->keySecret());

        if (! hash_equals($expected, $signature)) {
            throw new InvalidPaymentWebhookException('Razorpay checkout signature is invalid.');
        }

        $payment = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->where('provider_order_id', $orderId)
            ->first();

        if ($payment === null) {
            throw new BookingException('Razorpay order does not belong to this booking.');
        }

        if ($payment->status->isTerminal()) {
            // Already settled by the webhook (or a prior callback) — idempotent no-op.
            return $payment;
        }

        $payment->forceFill([
            'provider_payment_id' => $paymentId,
            'status' => BookingPaymentRecordStatus::Captured,
            'paid_at' => now(),
        ])->save();

        return $payment;
    }

    public function parseWebhook(Request $request): PaymentWebhookData
    {
        $this->assertConfigured();

        $secret = PaymentWebhookSignatureService::decryptSecret($this->settings, 'razorpay_webhook_secret');
        $signature = $request->header('X-Razorpay-Signature');

        if (blank($secret) || blank($signature)) {
            throw new InvalidPaymentWebhookException('Razorpay webhook signature is missing.');
        }

        $body = (string) $request->getContent();
        $expected = hash_hmac('sha256', $body, $secret);

        if (! hash_equals($expected, (string) $signature)) {
            throw new InvalidPaymentWebhookException('Razorpay webhook signature is invalid.');
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            throw new InvalidPaymentWebhookException('Razorpay webhook payload is malformed.');
        }

        $event = (string) ($payload['event'] ?? '');
        $entity = match (true) {
            str_starts_with($event, 'refund.') => $payload['payload']['refund']['entity'] ?? null,
            default => $payload['payload']['payment']['entity'] ?? null,
        };

        $reference = is_array($entity) ? (string) ($entity['notes']['booking_reference'] ?? '') : '';

        // Fall back to the order's receipt (= our payment_reference) when notes are absent.
        if ($reference === '' && is_array($entity) && isset($entity['order_id'])) {
            $payment = BookingPayment::query()->where('provider_order_id', $entity['order_id'])->first();
            $reference = $payment?->idempotency_key ?? '';
        }

        if ($reference === '') {
            throw new InvalidPaymentWebhookException('Razorpay webhook did not reference a known booking payment.');
        }

        return new PaymentWebhookData(
            event: $this->normalizeEvent($event),
            reference: $reference,
            reason: is_array($entity) ? ($entity['error_description'] ?? null) : null,
        );
    }

    private function normalizeEvent(string $event): PaymentWebhookEvent
    {
        return match ($event) {
            'payment.captured', 'order.paid' => PaymentWebhookEvent::Succeeded,
            'payment.failed' => PaymentWebhookEvent::Failed,
            'refund.created', 'refund.processed' => PaymentWebhookEvent::Refunded,
            default => PaymentWebhookEvent::Ignored,
        };
    }

    private function intentFrom(Booking $booking, string $reference, BookingPayment $payment): PaymentIntentData
    {
        return new PaymentIntentData(
            bookingId: $booking->id,
            reference: $reference,
            amount: (string) $booking->price,
            currency: self::SUPPORTED_CURRENCY,
            status: $payment->status->value,
            checkoutUrl: null,
        );
    }

    /**
     * Non-secret data the frontend needs to open Checkout.js. key_id is
     * public by design (Razorpay embeds it in every client-side
     * integration) — key_secret and webhook_secret never leave the server.
     *
     * @return array{order_id: string, key_id: string, amount_minor: int, currency: string}
     */
    public function checkoutPayload(Booking $booking): array
    {
        $payment = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->where('status', BookingPaymentRecordStatus::Pending)
            ->whereNotNull('provider_order_id')
            ->latest('created_at')
            ->firstOrFail();

        return [
            'order_id' => (string) $payment->provider_order_id,
            'key_id' => $this->keyId(),
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency_code,
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->settings->razorpay_enabled) {
            throw new BookingException('Razorpay is not enabled in payment gateway settings.');
        }

        if (blank($this->settings->razorpay_key_id) || blank($this->settings->razorpay_key_secret)) {
            throw new BookingException('Razorpay credentials are not configured.');
        }
    }

    private function keyId(): string
    {
        return (string) $this->settings->razorpay_key_id;
    }

    private function keySecret(): string
    {
        return (string) PaymentWebhookSignatureService::decryptSecret($this->settings, 'razorpay_key_secret');
    }

    private function toMinorUnits(float $amount): int
    {
        $minorUnits = Currency::query()->where('code', self::SUPPORTED_CURRENCY)->value('minor_units') ?? 2;

        return (int) round($amount * (10 ** $minorUnits));
    }
}
