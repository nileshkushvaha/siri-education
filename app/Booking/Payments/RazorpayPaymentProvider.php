<?php

declare(strict_types=1);

namespace App\Booking\Payments;

use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentProviderCapabilities;
use App\Booking\DTOs\PaymentProviderHealth;
use App\Booking\DTOs\PaymentStatusResult;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\PaymentWebhookEvent;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Services\PaymentProviderConfigValidator;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Currency;
use App\Models\Payment;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Razorpay Orders API + Checkout.js integration, covering both approved
 * Version 1 markets: India/INR and — once International Payments is
 * activated on the merchant account — United States/USD. Any other
 * booking currency is rejected before an order is ever created; see
 * approvedCurrencies().
 *
 * Amounts are always integer minor units derived from the currency's
 * own exponent (`currencies.minor_units`), never a hardcoded x100. The
 * decimal price on Booking is converted at this boundary and never
 * stored as a float anywhere in the payment record.
 *
 * SIRI records the CUSTOMER's transaction: a USD booking stays 4900 USD
 * on the attempt, the obligation, and the invoice. Razorpay separately
 * settles the Indian merchant account in INR at its own FX rate — that
 * is a provider-settlement fact this application deliberately does not
 * model, and it must never be allowed to rewrite the student's currency.
 *
 * verifyCheckout() handles the client-side Checkout.js callback
 * (order_id|payment_id HMAC'd with the key secret). It is deliberately
 * NON-AUTHORITATIVE: it proves the order belongs to this booking and
 * records provider_payment_id on the attempt, but never settles.
 *
 * Settlement belongs entirely to the signed server-to-server webhook,
 * which is parsed by PaymentWebhookEventParser and verified by
 * PaymentWebhookSignatureService before BookingPaymentSettlementService
 * applies it. This class deliberately carries no webhook parser of its
 * own — a second verification implementation is a second thing that can
 * be wrong about whether money arrived.
 */
final class RazorpayPaymentProvider implements PaymentProviderInterface
{
    private const KEY = 'razorpay';

    /** The domestic currency, always collectable when Razorpay is configured at all. */
    private const DOMESTIC_CURRENCY = 'INR';

    /**
     * The international currencies SIRI will collect once the merchant
     * account is attested — the DEFAULT for
     * `razorpay_international_currencies`, not a hardcoded ceiling.
     *
     * Razorpay's own list runs to 130+ currencies and, critically, is
     * per-merchant: their documentation says a currency outside the
     * standard set requires a support request, so no static list in this
     * codebase can be authoritative about a specific account. That is
     * why the operative value is a setting an operator confirms against
     * their Dashboard, and why this constant only seeds it.
     *
     * These six are the launch currencies Razorpay's public
     * documentation confirms. NZD and SAR are deliberately ABSENT: they
     * could not be verified from official documentation, so enabling
     * them is an explicit operator action after checking the account
     * rather than an assumption baked into code. Their markets stay
     * academically live and payment-blocked until then.
     *
     * @var list<string>
     */
    public const DEFAULT_INTERNATIONAL_CURRENCIES = ['USD', 'GBP', 'AUD', 'CAD', 'AED', 'SGD'];

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

        $currencyCode = strtoupper((string) ($booking->currency ?? self::DOMESTIC_CURRENCY));
        $approved = self::approvedCurrencies($this->settings);

        if (! in_array($currencyCode, $approved, true)) {
            throw new BookingException(sprintf(
                'Razorpay only supports %s in this phase (booking currency: %s).',
                implode('/', $approved),
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

        $amountMinor = $this->toMinorUnits((float) $booking->price, $currencyCode);

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
                'currency' => $currencyCode,
                'receipt' => $reference,
                'notes' => [
                    'booking_id' => $booking->id,
                    // Deliberately the PAYMENT reference ($reference,
                    // = booking_payments.idempotency_key, same value as
                    // `receipt` above), not $booking->reference — mirrors
                    // the identical fix in StripePaymentProvider.
                    // The webhook parser reads this back to resolve the
                    // exact attempt. Using the
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

        // The order id lives on the Payment ATTEMPT, not on the
        // obligation. Booking collection moved onto the generic attempt
        // ledger, so `booking_payments.provider_order_id` is no longer
        // written by any checkout path — resolving through it here made
        // every callback throw "order does not belong to this booking".
        // The obligation is reached FROM the attempt, which also proves
        // the order really belongs to this booking rather than trusting
        // the browser-supplied id.
        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->first();

        if ($obligation === null) {
            throw new BookingException('Razorpay order does not belong to this booking.');
        }

        $attempt = Payment::query()
            ->forPayable(BookingPayment::PAYABLE_TYPE, (string) $obligation->getKey())
            ->where('provider_order_id', $orderId)
            ->first();

        if ($attempt === null) {
            throw new BookingException('Razorpay order does not belong to this booking.');
        }

        if ($attempt->status->isTerminal()) {
            // Already settled by the webhook (or a prior callback) — idempotent no-op.
            return $obligation;
        }

        // Records WHICH payment the gateway says succeeded, and nothing
        // more. The browser callback is explicitly non-authoritative:
        // it never moves the attempt to Paid, never captures the
        // obligation, and never confirms the booking — a signed webhook
        // is the only thing permitted to settle money. Anyone who can
        // replay a callback would otherwise be able to confirm a lesson
        // that was never paid for.
        if ($attempt->provider_payment_id === null) {
            $attempt->forceFill(['provider_payment_id' => $paymentId])->save();
        }

        return $obligation;
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
            currency: (string) $payment->currency_code,
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
        // Third reader corrected for the ledger cutover (with
        // verifyCheckout() and refund()): the live order id is on the
        // open Payment ATTEMPT. Reading it from the obligation returned
        // nothing, so the checkout UI could not open Checkout.js at all.
        $attempt = $this->openAttemptFor($booking);

        return [
            'provider' => self::KEY,
            'order_id' => (string) $attempt->provider_order_id,
            'key_id' => $this->keyId(),
            'amount_minor' => (int) $attempt->amount_minor,
            'currency' => (string) $attempt->currency_code,
        ];
    }

    /**
     * The open, order-bearing attempt for a booking's obligation.
     *
     * @throws ModelNotFoundException<Payment> when no such attempt exists
     */
    private function openAttemptFor(Booking $booking): Payment
    {
        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->first();

        if ($obligation === null) {
            throw (new ModelNotFoundException)->setModel(Payment::class);
        }

        return Payment::query()
            ->forPayable(BookingPayment::PAYABLE_TYPE, (string) $obligation->getKey())
            ->open()
            ->whereNotNull('provider_order_id')
            ->latest('created_at')
            ->firstOrFail();
    }

    /**
     * Authenticated order-status poll — reconciliation's boundary call,
     * never the primary settlement path (the signed webhook
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
            // PAY-1: the order body already carried these; discarding
            // them is what let the reconciliation path settle without
            // checking what was collected.
            verifiedAmountMinor: isset($order['amount']) ? (int) $order['amount'] : null,
            verifiedCurrency: isset($order['currency']) ? strtoupper((string) $order['currency']) : null,
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
        return self::approvedCurrencies($this->settings);
    }

    /**
     * The currencies this Razorpay account may collect right now.
     *
     * INR is unconditional — domestic collection never depends on the
     * international attestation, so activating or revoking international
     * payments can never break India.
     *
     * The international set applies only when an operator has attested
     * that Razorpay activated International Payments on the merchant
     * account (`razorpay_international_enabled`), and is then limited to
     * the currencies that operator confirmed
     * (`razorpay_international_currencies`). Neither is inferred from
     * live credentials, which a domestic-only account also has.
     *
     * Static and settings-driven so PaymentProviderResolver can ask the
     * same question during routing without constructing a provider, and
     * so there is exactly ONE definition of what Razorpay may collect.
     *
     * @return list<string>
     */
    public static function approvedCurrencies(PaymentGatewaySettings $settings): array
    {
        if (! $settings->razorpay_international_enabled) {
            return [self::DOMESTIC_CURRENCY];
        }

        $international = array_values(array_unique(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $settings->razorpay_international_currencies,
        )));

        // INR is domestic and never depends on the international
        // attestation, so it can never be removed by editing that list.
        return [self::DOMESTIC_CURRENCY, ...array_values(array_diff($international, [self::DOMESTIC_CURRENCY]))];
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

    private function toMinorUnits(float $amount, string $currencyCode): int
    {
        $minorUnits = Currency::query()->where('code', $currencyCode)->value('minor_units') ?? 2;

        return (int) round($amount * (10 ** $minorUnits));
    }

    /**
     * Technical provider capability, kept separate from market rollout.
     *
     * `supportedStudentCountries` is deliberately EMPTY — read by
     * `supportsStudentCountry()` as "no technical restriction", not "no
     * country supported". Razorpay does not care which country a student
     * sits in; what SIRI approves is a COMMERCIAL decision, and it is
     * expressed where it belongs: the rollout scope, per-country
     * `payment_routing`, and the approved-currency list above (a market
     * cannot transact without a priced currency this account may
     * collect). Putting a market allowlist here would duplicate that
     * policy in a class whose job is describing the gateway.
     */
    public function capabilities(): PaymentProviderCapabilities
    {
        return new PaymentProviderCapabilities(
            provider: self::KEY,
            environment: app()->environment(),
            supportedStudentCountries: [],
            supportedBillingCurrencies: self::approvedCurrencies($this->settings),
            supportedCollectionCurrencies: self::approvedCurrencies($this->settings),
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
