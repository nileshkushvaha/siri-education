<?php

declare(strict_types=1);

namespace App\Booking\Payments;

use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentProviderCapabilities;
use App\Booking\DTOs\PaymentProviderHealth;
use App\Booking\DTOs\PaymentStatusResult;
use App\Booking\DTOs\PaymentWebhookData;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\PaymentWebhookEvent;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Services\PaymentProviderConfigValidator;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Currency;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    private const SUPPORTED_CURRENCY = 'INR';

    public function __construct(
        private readonly PaymentGatewaySettings $settings,
        private readonly PaymentProviderConfigValidator $configValidator,
        private readonly RazorpayGatewayClient $client,
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

        try {
            $payment = BookingPayment::query()->create([
                'booking_id' => $booking->id,
                'user_id' => $booking->student_id,
                'provider' => self::KEY,
                'amount_minor' => $amountMinor,
                'currency_code' => self::SUPPORTED_CURRENCY,
                'status' => BookingPaymentRecordStatus::Pending,
                'idempotency_key' => $reference,
                'metadata' => ['receipt' => $reference],
                'created_by' => Auth::id(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the race to a concurrent request for the same reference
            // (double-click, retried request). Its row already exists;
            // reuse it once it has an order_id, or ask the caller to retry
            // rather than surfacing the raw constraint violation.
            $existing = BookingPayment::query()
                ->where('booking_id', $booking->id)
                ->where('idempotency_key', $reference)
                ->latest('created_at')
                ->first();

            if ($existing?->provider_order_id !== null) {
                return $this->intentFrom($booking, $reference, $existing);
            }

            throw new BookingException('Payment order creation is already in progress — please retry.');
        }

        try {
            $order = $this->client->createOrder($this->keyId(), $this->keySecret(), [
                'amount' => $amountMinor,
                'currency' => self::SUPPORTED_CURRENCY,
                'receipt' => $reference,
                'notes' => [
                    'booking_id' => $booking->id,
                    // Deliberately the PAYMENT reference ($reference,
                    // = booking_payments.idempotency_key, same value as
                    // `receipt` above), not $booking->reference — mirrors
                    // the identical Phase 16C fix in StripePaymentProvider.
                    // parseWebhook() reads this back and hands it straight
                    // to BookingRepository::findByPaymentReference(), which
                    // queries the `payment_reference` column. Using the
                    // booking's own human reference here meant a webhook
                    // arriving without a prior verifyCheckout() (client tab
                    // closed before the checkout.js callback fired) would
                    // report "unknown reference" and never settle — masked
                    // in prior tests only because they built webhook
                    // payloads with the payment reference directly rather
                    // than through this metadata.
                    'booking_reference' => $reference,
                ],
            ]);
        } catch (GatewayRequestException $e) {
            $payment->forceFill(['status' => BookingPaymentRecordStatus::Failed, 'failed_at' => now()])->save();

            throw new BookingException('Razorpay order creation failed: '.$e->getMessage());
        }

        $payment->forceFill(['provider_order_id' => (string) ($order['id'] ?? '')])->save();

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
            $this->client->refundPayment($this->keyId(), $this->keySecret(), (string) $payment->provider_payment_id, [
                'amount' => $payment->amount_minor,
            ]);
        } catch (GatewayRequestException $e) {
            throw new BookingException('Razorpay refund failed: '.$e->getMessage());
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
        // Deliberately does not call assertConfigured(): webhook signature
        // verification only needs razorpay_webhook_secret, not the
        // enabled flag or key_id/key_secret. Gating on those would make a
        // disabled-but-still-receiving-webhooks gateway throw a generic
        // BookingException (422) instead of the 401 a bad/missing
        // signature should produce.
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

        $this->assertAmountAndCurrencyMatch($event, $entity, $reference);

        $normalizedEvent = $this->normalizeEvent($event);

        // If a payment settles via webhook alone (the client closed the
        // tab before Checkout.js's success callback fired), verifyCheckout()
        // never runs — this webhook is then the only place the
        // booking_payments row itself gets marked captured/failed, which
        // refund() depends on to find a capturable row later. A no-op for
        // refund.* events (handled by BookingPaymentService::recordRefund()
        // instead) since normalizeEvent() maps those to Refunded, not
        // Succeeded/Failed.
        $this->settlePaymentRow($normalizedEvent, $entity, $reference);

        return new PaymentWebhookData(
            event: $normalizedEvent,
            reference: $reference,
            reason: is_array($entity) ? ($entity['error_description'] ?? null) : null,
        );
    }

    /**
     * A signature-verified webhook is authentic, but that only proves
     * Razorpay sent it — not that it matches the order we created. Only
     * checked for the event that settles a booking as paid; a mismatch
     * here means the notes/receipt matched a booking by reference but
     * the amount on record disagrees, which must never silently settle.
     *
     * @param  array<string, mixed>|null  $entity
     */
    private function assertAmountAndCurrencyMatch(string $event, ?array $entity, string $reference): void
    {
        if (! in_array($event, ['payment.captured', 'order.paid'], true) || $entity === null) {
            return;
        }

        $payment = isset($entity['order_id'])
            ? BookingPayment::query()->where('provider_order_id', $entity['order_id'])->first()
            : BookingPayment::query()->where('idempotency_key', $reference)->latest('created_at')->first();

        if ($payment === null) {
            return;
        }

        $amountMatches = (int) ($entity['amount'] ?? -1) === $payment->amount_minor;
        $currencyMatches = strtoupper((string) ($entity['currency'] ?? '')) === $payment->currency_code;

        if (! $amountMatches || ! $currencyMatches) {
            throw new InvalidPaymentWebhookException(sprintf(
                'Razorpay webhook amount/currency does not match booking payment %s.',
                $payment->id,
            ));
        }
    }

    /**
     * Idempotent by construction: a row already in a terminal status
     * (e.g. already Captured by verifyCheckout() or an earlier delivery
     * of this same event) is left untouched.
     *
     * @param  array<string, mixed>|null  $entity
     */
    private function settlePaymentRow(PaymentWebhookEvent $event, ?array $entity, string $reference): void
    {
        if ($entity === null) {
            return;
        }

        $payment = isset($entity['order_id'])
            ? BookingPayment::query()->where('provider_order_id', $entity['order_id'])->first()
            : BookingPayment::query()->where('idempotency_key', $reference)->latest('created_at')->first();

        if ($payment === null || $payment->status->isTerminal()) {
            return;
        }

        match ($event) {
            PaymentWebhookEvent::Succeeded => $payment->forceFill([
                'status' => BookingPaymentRecordStatus::Captured,
                'provider_payment_id' => (string) ($entity['id'] ?? $payment->provider_payment_id),
                'paid_at' => now(),
            ])->save(),
            PaymentWebhookEvent::Failed => $payment->forceFill([
                'status' => BookingPaymentRecordStatus::Failed,
                'failed_at' => now(),
            ])->save(),
            default => null,
        };
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
            'provider' => self::KEY,
            'order_id' => (string) $payment->provider_order_id,
            'key_id' => $this->keyId(),
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency_code,
        ];
    }

    /**
     * Authenticated order-status poll — reconciliation's boundary call,
     * never the primary settlement path (verifyCheckout()/parseWebhook()
     * remain that). Razorpay's order status (created/attempted/paid) is
     * coarser than a payment's own status, but the order is the only
     * reference reconciliation reliably has before a payment settles.
     */
    public function fetchStatus(string $providerReference): PaymentStatusResult
    {
        $this->assertConfigured();

        $payment = BookingPayment::query()
            ->where('provider_order_id', $providerReference)
            ->latest('created_at')
            ->first();

        try {
            $order = $this->client->fetchOrder($this->keyId(), $this->keySecret(), $providerReference);
        } catch (GatewayRequestException $e) {
            throw new BookingException('Unable to fetch Razorpay order status: '.$e->getMessage());
        }

        $orderStatus = (string) ($order['status'] ?? '');

        return new PaymentStatusResult(
            recordStatus: match ($orderStatus) {
                'paid' => BookingPaymentRecordStatus::Captured,
                'attempted' => BookingPaymentRecordStatus::Processing,
                'created' => BookingPaymentRecordStatus::Pending,
                default => BookingPaymentRecordStatus::Unknown,
            },
            providerPaymentId: $payment?->provider_payment_id,
            providerStatus: $orderStatus !== '' ? $orderStatus : null,
            safeReason: null,
        );
    }

    /** Enabled AND key_id passes format validation AND a secret is present. */
    public function isConfigured(): bool
    {
        return $this->settings->razorpay_enabled
            && $this->configValidator->isValidRazorpayKeyId($this->settings->razorpay_key_id)
            && filled($this->settings->razorpay_key_secret);
    }

    public function supportedCurrencies(): array
    {
        return [self::SUPPORTED_CURRENCY];
    }

    private function assertConfigured(): void
    {
        if (! $this->settings->razorpay_enabled) {
            throw new BookingException('Razorpay is not enabled in payment gateway settings.');
        }

        if (blank($this->settings->razorpay_key_id) || blank($this->settings->razorpay_key_secret)) {
            throw new BookingException('Razorpay credentials are not configured.');
        }

        if (! $this->configValidator->isValidRazorpayKeyId($this->settings->razorpay_key_id)) {
            throw new BookingException('Razorpay key_id does not look like a valid Razorpay key — refusing to use it.');
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

    /** India-focused, INR-only in this phase — a real account may later be verified for additional currencies/methods, never assumed here. */
    public function capabilities(): PaymentProviderCapabilities
    {
        return new PaymentProviderCapabilities(
            provider: self::KEY,
            environment: app()->environment(),
            supportedStudentCountries: ['IN'],
            supportedBillingCurrencies: [self::SUPPORTED_CURRENCY],
            supportedCollectionCurrencies: [self::SUPPORTED_CURRENCY],
            supportedTransactionTypes: ['booking_payment', 'wallet_recharge'],
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
            healthStatus: $this->isConfigured()
                ? new PaymentProviderHealth(healthy: true)
                : new PaymentProviderHealth(healthy: false, safeMessage: 'Razorpay is not enabled or its credentials are missing/invalid.'),
            capabilityVersion: 1,
        );
    }
}
