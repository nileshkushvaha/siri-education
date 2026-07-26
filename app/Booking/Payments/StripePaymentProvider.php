<?php

declare(strict_types=1);

namespace App\Booking\Payments;

use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\Contracts\StripeGatewayClient;
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
 * Stripe PaymentIntents + webhook integration via the official
 * `stripe/stripe-php` SDK (isolated behind StripeGatewayClient — this
 * class never instantiates \Stripe\StripeClient directly). Mirrors
 * RazorpayPaymentProvider's shape (same idempotency/terminal-state
 * discipline via the shared BookingPaymentService::markPaid()) but
 * Stripe's own model differs in two ways that show up below:
 *
 *  - no client-side "checkout signature" step exists here. Stripe
 *    Elements/Checkout confirms the PaymentIntent directly with
 *    Stripe using client_secret; this integration settles a booking
 *    only from the server-to-server webhook
 *    (payment_intent.succeeded), never from a client callback — so
 *    there is deliberately no verifyCheckout() equivalent.
 *  - client_secret is single-use, frontend-facing, and must never be
 *    persisted (unlike Razorpay's order_id, which is not sensitive).
 *    createPayment() returns it once, directly, in PaymentIntentData;
 *    checkoutPayload() re-fetches it from Stripe's retrieve-intent
 *    endpoint rather than ever writing it to booking_payments.metadata.
 *
 * Frontend Stripe.js/Elements integration lives in
 * resources/views/livewire/frontend/booking/partials/stripe-checkout-script.blade.php.
 * Real Stripe credentials are configured per-environment through
 * Payment Gateway settings, the same as every other provider here.
 */
final class StripePaymentProvider implements PaymentProviderInterface
{
    private const KEY = 'stripe';

    /** Stripe supports many currencies; INR is reserved for Razorpay by routing convention, not a Stripe limitation. */
    private const SUPPORTED_CURRENCIES = ['USD', 'GBP', 'EUR', 'AED'];

    public function __construct(
        private readonly PaymentGatewaySettings $settings,
        private readonly PaymentProviderConfigValidator $configValidator,
        private readonly PaymentWebhookSignatureService $signatureService,
        private readonly StripeGatewayClient $client,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function createPayment(Booking $booking, string $reference): PaymentIntentData
    {
        $this->assertConfigured();

        $currencyCode = strtoupper((string) ($booking->currency ?? ''));

        if (! in_array($currencyCode, self::SUPPORTED_CURRENCIES, true)) {
            throw new BookingException(sprintf(
                'Stripe does not support currency %s in this phase.',
                $currencyCode,
            ));
        }

        $existingAttempt = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->where('idempotency_key', $reference)
            ->latest('created_at')
            ->first();

        if ($existingAttempt?->provider_order_id !== null) {
            // No re-fetch here: initiate()'s return value is not what the
            // frontend actually uses (checkoutPayload() is, called
            // explicitly, always fresh) — re-fetching on every reused
            // initiate() would turn a free idempotent no-op into an
            // avoidable Stripe API call.
            return $this->intentFrom($booking, $reference, $existingAttempt, clientSecret: '');
        }

        $amountMinor = $this->toMinorUnits((float) $booking->price, $currencyCode);

        if ($existingAttempt !== null) {
            // A prior attempt for this exact reference exists but never
            // reached the gateway (Pending — a concurrent request is
            // likely still in flight — or Failed — the gateway call
            // itself errored last time, e.g. a timeout). idempotency_key
            // is unique, so a genuine retry after a definitive failure
            // must reuse this row, never attempt a second INSERT: reset
            // it in place and try the gateway call again.
            $payment = $existingAttempt;
            $payment->forceFill(['status' => BookingPaymentRecordStatus::Pending, 'failed_at' => null])->save();
        } else {
            try {
                $payment = BookingPayment::query()->create([
                    'booking_id' => $booking->id,
                    'user_id' => $booking->student_id,
                    'provider' => self::KEY,
                    'amount_minor' => $amountMinor,
                    'currency_code' => $currencyCode,
                    'status' => BookingPaymentRecordStatus::Pending,
                    'idempotency_key' => $reference,
                    'metadata' => ['receipt' => $reference],
                    'created_by' => Auth::id(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost the race to a concurrent request for the same
                // reference (double-click, retried request). Its row
                // already exists; reuse it once it has an order_id, or ask
                // the caller to retry rather than surfacing the raw
                // constraint violation.
                $existing = BookingPayment::query()
                    ->where('booking_id', $booking->id)
                    ->where('idempotency_key', $reference)
                    ->latest('created_at')
                    ->first();

                if ($existing?->provider_order_id !== null) {
                    return $this->intentFrom($booking, $reference, $existing, clientSecret: '');
                }

                throw new BookingException('Payment intent creation is already in progress — please retry.');
            }
        }

        try {
            // Stripe's own idempotency-key support: a retried call with the
            // same reference is deduplicated Stripe-side too, not just at
            // our booking_payments unique-constraint layer.
            $intent = $this->client->createPaymentIntent($this->secretKey(), [
                'amount' => $amountMinor,
                'currency' => strtolower($currencyCode),
                'metadata' => [
                    'booking_id' => $booking->id,
                    // Deliberately the PAYMENT reference ($reference,
                    // = booking_payments.idempotency_key), not $booking->reference
                    // — parseWebhook() reads this back and hands it straight to
                    // BookingRepository::findByPaymentReference(), which queries
                    // the `payment_reference` column. Using the booking's own
                    // human reference here was a real bug: a genuine Stripe
                    // webhook would report "unknown reference" and never settle,
                    // since Stripe is the only settlement path this provider has
                    // (no verifyCheckout() equivalent) — masked in prior tests
                    // only because they built webhook payloads with the payment
                    // reference directly rather than through this metadata.
                    'booking_reference' => $reference,
                ],
            ], $reference);
        } catch (GatewayRequestException $e) {
            $payment->forceFill(['status' => BookingPaymentRecordStatus::Failed, 'failed_at' => now()])->save();

            throw new BookingException('Stripe payment intent creation failed: '.$e->getMessage());
        }

        $payment->forceFill(['provider_order_id' => (string) ($intent['id'] ?? '')])->save();

        return $this->intentFrom($booking, $reference, $payment, (string) ($intent['client_secret'] ?? ''));
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
            throw new BookingException(sprintf('Booking %s has no captured Stripe payment to refund.', $booking->reference));
        }

        try {
            $this->client->createRefund($this->secretKey(), [
                'payment_intent' => $payment->provider_payment_id,
                'amount' => $payment->amount_minor,
            ]);
        } catch (GatewayRequestException $e) {
            throw new BookingException('Stripe refund failed: '.$e->getMessage());
        }

        $payment->forceFill(['status' => BookingPaymentRecordStatus::Refunded])->save();
    }

    /**
     * Server-to-server webhook — the only settlement path for Stripe in
     * this phase. Signature verification reuses
     * PaymentWebhookSignatureService::verifyStripe() (already built for
     * the generic gateway scaffold's Stripe-signature check; not
     * previously wired to booking settlement).
     *
     * @throws InvalidPaymentWebhookException when the signature is missing/invalid or the payload is malformed
     */
    public function parseWebhook(Request $request): PaymentWebhookData
    {
        $secret = PaymentWebhookSignatureService::decryptSecret($this->settings, 'stripe_webhook_secret');

        if (blank($secret)) {
            throw new InvalidPaymentWebhookException('Stripe webhook secret is not configured.');
        }

        if (! $this->signatureService->isValid('stripe', $request, $this->settings)) {
            throw new InvalidPaymentWebhookException('Stripe webhook signature is invalid.');
        }

        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            throw new InvalidPaymentWebhookException('Stripe webhook payload is malformed.');
        }

        $event = (string) ($payload['type'] ?? '');
        $intent = $payload['data']['object'] ?? null;

        if (! is_array($intent)) {
            throw new InvalidPaymentWebhookException('Stripe webhook payload is missing its object data.');
        }

        $reference = is_array($intent['metadata'] ?? null)
            ? (string) ($intent['metadata']['booking_reference'] ?? '')
            : '';

        if ($reference === '' && isset($intent['id'])) {
            $payment = BookingPayment::query()->where('provider_order_id', $intent['id'])->first();
            $reference = $payment?->idempotency_key ?? '';
        }

        if ($reference === '') {
            throw new InvalidPaymentWebhookException('Stripe webhook did not reference a known booking payment.');
        }

        $this->assertAmountAndCurrencyMatch($event, $intent, $reference);

        $normalizedEvent = $this->normalizeEvent($event);

        // Stripe has no client-side checkout-verify step (unlike Razorpay's
        // verifyCheckout()) — this webhook is the only place the
        // booking_payments row itself ever gets marked captured/failed,
        // which this class's own refund() depends on to find a capturable
        // row later.
        $this->settlePaymentRow($normalizedEvent, $intent, $reference);

        return new PaymentWebhookData(
            event: $normalizedEvent,
            reference: $reference,
            reason: is_array($intent['last_payment_error'] ?? null)
                ? ($intent['last_payment_error']['message'] ?? null)
                : null,
        );
    }

    /**
     * Idempotent by construction: a row already in a terminal status
     * (e.g. already Captured by an earlier delivery of the same event)
     * is left untouched — BookingPaymentService::markPaid()'s own
     * assertReference() is what makes the *booking* side idempotent;
     * this only keeps the booking_payments row consistent with it.
     *
     * @param  array<string, mixed>  $intent
     */
    private function settlePaymentRow(PaymentWebhookEvent $event, array $intent, string $reference): void
    {
        $payment = isset($intent['id'])
            ? BookingPayment::query()->where('provider_order_id', $intent['id'])->first()
            : BookingPayment::query()->where('idempotency_key', $reference)->latest('created_at')->first();

        if ($payment === null || $payment->status->isTerminal()) {
            return;
        }

        match ($event) {
            PaymentWebhookEvent::Succeeded => $payment->forceFill([
                'status' => BookingPaymentRecordStatus::Captured,
                'provider_payment_id' => (string) ($intent['id'] ?? $payment->provider_order_id),
                'paid_at' => now(),
            ])->save(),
            PaymentWebhookEvent::Failed => $payment->forceFill([
                'status' => BookingPaymentRecordStatus::Failed,
                'failed_at' => now(),
            ])->save(),
            // SCA/3DS in progress — never touches the booking; a later
            // webhook (succeeded/failed) is what actually settles it.
            PaymentWebhookEvent::Processing => $payment->forceFill([
                'status' => BookingPaymentRecordStatus::Processing,
            ])->save(),
            default => null,
        };
    }

    /** Enabled AND all three credentials pass Stripe's own key-prefix format. */
    public function isConfigured(): bool
    {
        return $this->settings->stripe_enabled
            && $this->configValidator->isValidStripePublishableKey($this->settings->stripe_publishable_key)
            && $this->configValidator->isValidStripeSecretKey(
                PaymentWebhookSignatureService::decryptSecret($this->settings, 'stripe_secret_key'),
            );
    }

    public function supportedCurrencies(): array
    {
        return self::SUPPORTED_CURRENCIES;
    }

    /**
     * @return array{provider: string, payment_intent_id: string, client_secret: string, publishable_key: string, amount_minor: int, currency: string}
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
            'payment_intent_id' => (string) $payment->provider_order_id,
            'client_secret' => $this->retrieveClientSecret($payment),
            'publishable_key' => (string) $this->settings->stripe_publishable_key,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency_code,
        ];
    }

    /**
     * Authenticated PaymentIntent status poll — reconciliation's
     * boundary call, never the primary settlement path (the webhook
     * remains that). Reuses the same retrieve-intent call as
     * retrieveClientSecret(), so no second gateway seam is introduced.
     */
    public function fetchStatus(string $providerReference): PaymentStatusResult
    {
        $this->assertConfigured();

        $payment = BookingPayment::query()
            ->where('provider_order_id', $providerReference)
            ->latest('created_at')
            ->first();

        try {
            $intent = $this->client->retrievePaymentIntent($this->secretKey(), $providerReference);
        } catch (GatewayRequestException $e) {
            throw new BookingException('Unable to fetch Stripe payment intent status: '.$e->getMessage());
        }

        $intentStatus = (string) ($intent['status'] ?? '');

        return new PaymentStatusResult(
            recordStatus: match ($intentStatus) {
                'succeeded' => BookingPaymentRecordStatus::Captured,
                'processing' => BookingPaymentRecordStatus::Processing,
                'requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture' => BookingPaymentRecordStatus::Pending,
                'canceled' => BookingPaymentRecordStatus::Cancelled,
                default => BookingPaymentRecordStatus::Unknown,
            },
            providerPaymentId: (string) ($intent['id'] ?? $payment?->provider_order_id) ?: null,
            providerStatus: $intentStatus !== '' ? $intentStatus : null,
            safeReason: is_array($intent['last_payment_error'] ?? null)
                ? ($intent['last_payment_error']['message'] ?? null)
                : null,
        );
    }

    /**
     * A signature-verified webhook proves Stripe sent it, not that it
     * matches the order this integration created — only checked for the
     * event that settles a booking as paid, mirroring
     * RazorpayPaymentProvider::assertAmountAndCurrencyMatch().
     *
     * @param  array<string, mixed>  $intent
     */
    private function assertAmountAndCurrencyMatch(string $event, array $intent, string $reference): void
    {
        if ($event !== 'payment_intent.succeeded') {
            return;
        }

        $payment = isset($intent['id'])
            ? BookingPayment::query()->where('provider_order_id', $intent['id'])->first()
            : BookingPayment::query()->where('idempotency_key', $reference)->latest('created_at')->first();

        if ($payment === null) {
            return;
        }

        $amountMatches = (int) ($intent['amount'] ?? -1) === $payment->amount_minor;
        $currencyMatches = strtoupper((string) ($intent['currency'] ?? '')) === $payment->currency_code;

        if (! $amountMatches || ! $currencyMatches) {
            throw new InvalidPaymentWebhookException(sprintf(
                'Stripe webhook amount/currency does not match booking payment %s.',
                $payment->id,
            ));
        }
    }

    private function normalizeEvent(string $event): PaymentWebhookEvent
    {
        return match ($event) {
            'payment_intent.succeeded' => PaymentWebhookEvent::Succeeded,
            'payment_intent.payment_failed' => PaymentWebhookEvent::Failed,
            'payment_intent.processing' => PaymentWebhookEvent::Processing,
            'charge.refunded' => PaymentWebhookEvent::Refunded,
            default => PaymentWebhookEvent::Ignored,
        };
    }

    private function intentFrom(Booking $booking, string $reference, BookingPayment $payment, string $clientSecret): PaymentIntentData
    {
        return new PaymentIntentData(
            bookingId: $booking->id,
            reference: $reference,
            amount: (string) $booking->price,
            currency: $payment->currency_code,
            status: $payment->status->value,
            checkoutUrl: null,
            publicKey: (string) $this->settings->stripe_publishable_key,
            clientSecret: $clientSecret,
        );
    }

    /**
     * Re-fetches client_secret from Stripe rather than ever persisting
     * it — see class docblock. Stripe's retrieve-intent endpoint
     * returns client_secret for as long as the secret key that created
     * it is used to fetch it.
     */
    private function retrieveClientSecret(BookingPayment $payment): string
    {
        try {
            $intent = $this->client->retrievePaymentIntent($this->secretKey(), (string) $payment->provider_order_id);
        } catch (GatewayRequestException $e) {
            throw new BookingException('Unable to retrieve Stripe payment intent: '.$e->getMessage());
        }

        return (string) ($intent['client_secret'] ?? '');
    }

    private function assertConfigured(): void
    {
        if (! $this->settings->stripe_enabled) {
            throw new BookingException('Stripe is not enabled in payment gateway settings.');
        }

        if (! $this->configValidator->isValidStripeSecretKey($this->secretKey())) {
            throw new BookingException('Stripe secret key is missing or does not look like a valid Stripe key — refusing to use it.');
        }

        if (! $this->configValidator->isValidStripePublishableKey($this->settings->stripe_publishable_key)) {
            throw new BookingException('Stripe publishable key is missing or does not look like a valid Stripe key.');
        }
    }

    private function secretKey(): string
    {
        return (string) PaymentWebhookSignatureService::decryptSecret($this->settings, 'stripe_secret_key');
    }

    private function toMinorUnits(float $amount, string $currencyCode): int
    {
        $minorUnits = Currency::query()->where('code', $currencyCode)->value('minor_units') ?? 2;

        return (int) round($amount * (10 ** $minorUnits));
    }

    /**
     * International-currency focused; INR is deliberately excluded (see
     * SUPPORTED_CURRENCIES) — reserved for Razorpay by routing
     * convention. No frontend Stripe.js/Elements integration exists yet
     * (this class is the tested backend half only), so no student
     * country is asserted as verified — empty list until that changes.
     */
    public function capabilities(): PaymentProviderCapabilities
    {
        return new PaymentProviderCapabilities(
            provider: self::KEY,
            environment: app()->environment(),
            supportedStudentCountries: [],
            supportedBillingCurrencies: self::SUPPORTED_CURRENCIES,
            supportedCollectionCurrencies: self::SUPPORTED_CURRENCIES,
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
                : new PaymentProviderHealth(healthy: false, safeMessage: 'Stripe is not enabled or its credentials are missing/invalid.'),
            capabilityVersion: 1,
        );
    }
}
