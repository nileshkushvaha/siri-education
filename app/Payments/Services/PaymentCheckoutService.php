<?php

declare(strict_types=1);

namespace App\Payments\Services;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Payments\FakePaymentProvider;
use App\Models\Payment;
use App\Payments\Contracts\Payable;
use App\Payments\DTOs\PaymentCheckoutData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\PaymentAttemptAlreadyOpenException;
use App\Payments\Exceptions\PaymentException;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Support\Str;

/**
 * Phase 4B.2 — turns a Payable into a live gateway checkout, for any
 * payable, through the SAME low-level gateway clients the Booking and
 * Wallet flows use.
 *
 * There is deliberately no package-specific gateway client, no second
 * Razorpay/Stripe SDK wrapper, and no duplicate signature verification:
 * this class only orchestrates. Provider SELECTION is not its job
 * either — the calling domain service resolves that through the
 * existing PaymentProviderResolver and passes a key in.
 *
 * Responsibility split:
 *   {domain} service      — who may pay, for what, in which currency
 *   PaymentCheckoutService — attempt <-> provider-order orchestration
 *   PaymentService         — attempt record mechanics
 *   *GatewayClient         — the external provider API
 *
 * Ordering mirrors WalletRechargeService::initiate(): the local attempt
 * row is written FIRST, then the provider is called. A gateway failure
 * therefore leaves a durable Failed attempt rather than an invisible
 * one, and can never leave a provider order with no local record.
 *
 * Settlement is NOT here. Nothing in this class moves an attempt to
 * Paid — that requires a verified webhook (Phase 4B.3).
 */
final class PaymentCheckoutService
{
    /**
     * How long a non-initializing caller waits for the initializing
     * worker's order id — 10 x 100ms. Sized to one gateway round-trip,
     * deliberately not an open-ended poll: waiting longer would hold a
     * web request hostage to a provider that may never answer, and the
     * safe fallback (ask the student to try again) costs nothing.
     */
    private const int INITIALIZATION_WAIT_ATTEMPTS = 10;

    private const int INITIALIZATION_WAIT_MICROSECONDS = 100_000;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentGatewaySettings $gatewaySettings,
        private readonly RazorpayGatewayClient $razorpay,
        private readonly StripeGatewayClient $stripe,
    ) {}

    /**
     * Open checkout for a payable, resuming an existing open attempt
     * rather than creating a second one.
     *
     * Resuming is safe for every provider we support because no NEW
     * provider-side order is created: Razorpay's order id is stored on
     * the attempt, and Stripe's intent can be re-fetched for its
     * client_secret (which is deliberately never persisted). That is
     * why a double-clicked Pay button cannot produce two live gateway
     * orders for one payable.
     *
     * @throws PaymentException when the attempt cannot be opened or the gateway rejects the order
     */
    public function start(Payable $payable, string $provider): PaymentCheckoutData
    {
        $open = $this->openAttemptFor($payable);

        if ($open !== null) {
            return $this->resume($open, $payable);
        }

        try {
            $payment = $this->payments->startAttempt($payable, $provider, $this->newIdempotencyKey());
        } catch (PaymentAttemptAlreadyOpenException $e) {
            // Phase 4E.2 (spec Part 4) — we lost the race to open the
            // first attempt. The winner's attempt is the one true
            // checkout for this payable, so converge on it rather than
            // failing: the student double-clicked, they did not do
            // anything wrong.
            return $this->resume($e->attempt, $payable);
        }

        try {
            $payload = $this->createProviderOrder($payment, $payable);
        } catch (PaymentException $e) {
            // The attempt stays as durable evidence that we tried, and
            // stops the payable from being stuck behind an open row.
            $this->payments->transition($payment, PaymentStatus::Failed, [
                'failure_code' => 'provider_order_failed',
                'failure_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $this->checkoutData($payment->refresh(), $payload, resumed: false);
    }

    /**
     * Re-presents an already-open attempt. Creates a provider order
     * only if the attempt somehow has none yet; otherwise it rebuilds
     * the frontend payload from what the provider already holds.
     *
     * @throws PaymentException when the open attempt can no longer be presented
     */
    public function resume(Payment $payment, Payable $payable): PaymentCheckoutData
    {
        if (! $payment->status->isOpen()) {
            throw new PaymentException('That payment attempt has already been settled.');
        }

        if ($payment->provider_order_id === null) {
            return $this->checkoutData($payment->refresh(), $this->createProviderOrder($payment, $payable), resumed: true);
        }

        $payload = match ($payment->provider) {
            'razorpay' => $this->razorpayPayload($payment),
            'stripe' => $this->resumeStripePayload($payment),
            FakePaymentProvider::KEY => $this->fakePayload($payment),
            default => throw new PaymentException(sprintf('Checkout cannot be resumed through "%s".', $payment->provider)),
        };

        return $this->checkoutData($payment, $payload, resumed: true);
    }

    /** The single open attempt for a payable, if one exists. */
    public function openAttemptFor(Payable $payable): ?Payment
    {
        return Payment::query()
            ->forPayable($payable->paymentPayableType(), $payable->paymentPayableId())
            ->open()
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Explicitly abandons the open attempt so a fresh one can start.
     *
     * The provider-side order is deliberately left alone: we never had
     * money, an abandoned Razorpay order/Stripe intent simply goes
     * unused, and cancelling remotely would add a failure mode to a
     * purely local decision. The local attempt is what governs whether
     * a retry may begin.
     *
     * @throws PaymentException when there is nothing open to cancel
     */
    public function cancelOpenAttempt(Payable $payable, ?string $reason = null): Payment
    {
        $open = $this->openAttemptFor($payable);

        if ($open === null) {
            throw new PaymentException('There is no payment in progress to cancel.');
        }

        return $this->payments->transition($open, PaymentStatus::Cancelled, [
            'failure_code' => 'cancelled_by_user',
            'failure_message' => $reason,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function checkoutData(Payment $payment, array $payload, bool $resumed): PaymentCheckoutData
    {
        return new PaymentCheckoutData(
            paymentId: (string) $payment->id,
            provider: (string) $payment->provider,
            reference: (string) ($payment->idempotency_key ?? $payment->id),
            amountMinor: (int) $payment->amount_minor,
            currencyCode: (string) $payment->currency_code,
            checkoutPayload: $payload,
            resumed: $resumed,
        );
    }

    /**
     * Creates the provider-side order for an attempt — but only if this
     * caller wins the initialization claim.
     *
     * This is the Race B guard (spec Part 5). Closing the open-attempt
     * race leaves a narrower but equally expensive one: two requests
     * holding the SAME attempt, both seeing `provider_order_id = NULL`,
     * both calling the gateway. One local row, two live external
     * orders. The claim makes exactly one caller the initializer; every
     * other caller waits for that caller's order id and resumes it.
     *
     * @return array<string, mixed>
     */
    private function createProviderOrder(Payment $payment, Payable $payable): array
    {
        if (! $this->payments->claimInitialization($payment)) {
            // Either another worker is mid-initialization, or an order
            // already exists. Both mean the same thing here: do NOT
            // call the provider.
            return $this->awaitProviderOrder($payment);
        }

        return match ($payment->provider) {
            'razorpay' => $this->createRazorpayOrder($payment, $payable),
            'stripe' => $this->createStripeIntent($payment, $payable),
            FakePaymentProvider::KEY => $this->createFakeOrder($payment),
            default => throw new PaymentException(sprintf('Checkout is not available through "%s" yet.', $payment->provider)),
        };
    }

    /**
     * Waits briefly for the initializing worker to publish its order
     * id, then builds the payload from it.
     *
     * Bounded and short on purpose: the wait covers the gap between one
     * worker claiming initialization and it persisting the order id —
     * a single gateway round-trip, not an open-ended poll. If the id
     * never appears, the initializer failed or is still in flight, and
     * the honest answer is "try again" rather than creating a second
     * external order behind its back.
     *
     * @return array<string, mixed>
     */
    private function awaitProviderOrder(Payment $payment): array
    {
        for ($attempt = 0; $attempt < self::INITIALIZATION_WAIT_ATTEMPTS; $attempt++) {
            $fresh = $payment->fresh();

            if ($fresh === null) {
                break;
            }

            if ($fresh->provider_order_id !== null) {
                return match ($fresh->provider) {
                    'razorpay' => $this->razorpayPayload($fresh),
                    'stripe' => $this->resumeStripePayload($fresh),
                    FakePaymentProvider::KEY => $this->fakePayload($fresh),
                    default => throw new PaymentException(sprintf('Checkout cannot be resumed through "%s".', $fresh->provider)),
                };
            }

            if ($fresh->status->isTerminal()) {
                // The initializer failed and closed the attempt. A new
                // attempt is now permitted by the open-attempt
                // invariant, so the student can simply start again.
                throw new PaymentException('Your payment could not be started. Please try again.');
            }

            usleep(self::INITIALIZATION_WAIT_MICROSECONDS);
        }

        throw new PaymentException('Your payment is still being set up. Please try again in a moment.');
    }

    /** @return array<string, mixed> */
    private function createRazorpayOrder(Payment $payment, Payable $payable): array
    {
        [$keyId, $keySecret] = $this->razorpayCredentials();

        try {
            $order = $this->razorpay->createOrder($keyId, $keySecret, [
                'amount' => $payment->amount_minor,
                'currency' => $payment->currency_code,
                'receipt' => (string) $payment->idempotency_key,
                'notes' => [
                    'payable_type' => $payment->payable_type,
                    'payable_reference' => $payable->paymentReference(),
                ],
            ]);
        } catch (GatewayRequestException $e) {
            throw new PaymentException('Razorpay order creation failed: '.$e->getMessage());
        }

        $orderId = (string) ($order['id'] ?? '');

        if ($orderId === '') {
            throw new PaymentException('Razorpay did not return an order id.');
        }

        $this->payments->recordProviderOrder($payment, $orderId);

        return $this->razorpayPayload($payment->refresh());
    }

    /** @return array<string, mixed> */
    private function razorpayPayload(Payment $payment): array
    {
        [$keyId] = $this->razorpayCredentials();

        return [
            'provider' => 'razorpay',
            'order_id' => (string) $payment->provider_order_id,
            'key_id' => $keyId,
            'amount_minor' => (int) $payment->amount_minor,
            'currency' => (string) $payment->currency_code,
        ];
    }

    /**
     * `client_secret` is single-use and frontend-facing: returned here,
     * never written to `payments` (no column exists for it), never
     * logged, never placed in audit metadata — the same rule
     * StripePaymentProvider and WalletRechargeService already follow.
     *
     * @return array<string, mixed>
     */
    private function createStripeIntent(Payment $payment, Payable $payable): array
    {
        $secretKey = $this->stripeSecret();

        try {
            $intent = $this->stripe->createPaymentIntent($secretKey, [
                'amount' => $payment->amount_minor,
                'currency' => strtolower((string) $payment->currency_code),
                'metadata' => [
                    'payment_reference' => (string) $payment->idempotency_key,
                    'payable_type' => (string) $payment->payable_type,
                    'payable_reference' => $payable->paymentReference(),
                ],
            ], (string) $payment->idempotency_key);
        } catch (GatewayRequestException $e) {
            throw new PaymentException('Stripe payment intent creation failed: '.$e->getMessage());
        }

        $intentId = (string) ($intent['id'] ?? '');

        if ($intentId === '') {
            throw new PaymentException('Stripe did not return a payment intent id.');
        }

        $this->payments->recordProviderOrder($payment, $intentId);

        return $this->stripePayload($intentId, (string) ($intent['client_secret'] ?? ''), $payment);
    }

    /** Resuming re-fetches the intent purely to recover its client_secret — no second intent is created. */
    private function resumeStripePayload(Payment $payment): array
    {
        try {
            $intent = $this->stripe->retrievePaymentIntent($this->stripeSecret(), (string) $payment->provider_order_id);
        } catch (GatewayRequestException $e) {
            throw new PaymentException('Your payment could not be resumed: '.$e->getMessage());
        }

        return $this->stripePayload((string) $payment->provider_order_id, (string) ($intent['client_secret'] ?? ''), $payment);
    }

    /** @return array<string, mixed> */
    private function stripePayload(string $intentId, string $clientSecret, Payment $payment): array
    {
        return [
            'provider' => 'stripe',
            'payment_intent_id' => $intentId,
            'client_secret' => $clientSecret,
            'publishable_key' => (string) $this->gatewaySettings->stripe_publishable_key,
            'amount_minor' => (int) $payment->amount_minor,
            'currency' => (string) $payment->currency_code,
        ];
    }

    /** Test/local only — the resolver has already gated `fake` to local/testing before we get here. */
    private function createFakeOrder(Payment $payment): array
    {
        $this->payments->recordProviderOrder($payment, 'fake_payment_order_'.$payment->id);

        return $this->fakePayload($payment->refresh());
    }

    /** @return array<string, mixed> */
    private function fakePayload(Payment $payment): array
    {
        return [
            'provider' => FakePaymentProvider::KEY,
            'order_id' => (string) $payment->provider_order_id,
            'amount_minor' => (int) $payment->amount_minor,
            'currency' => (string) $payment->currency_code,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function razorpayCredentials(): array
    {
        if (! $this->gatewaySettings->razorpay_enabled || blank($this->gatewaySettings->razorpay_key_id) || blank($this->gatewaySettings->razorpay_key_secret)) {
            throw new PaymentException('Razorpay is not configured.');
        }

        return [
            (string) $this->gatewaySettings->razorpay_key_id,
            (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'razorpay_key_secret'),
        ];
    }

    private function stripeSecret(): string
    {
        if (! $this->gatewaySettings->stripe_enabled || blank($this->gatewaySettings->stripe_publishable_key) || blank($this->gatewaySettings->stripe_secret_key)) {
            throw new PaymentException('Stripe is not configured.');
        }

        return (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'stripe_secret_key');
    }

    private function newIdempotencyKey(): string
    {
        return 'PAY-'.strtoupper(Str::random(16));
    }
}
