<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentWebhookData;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Models\Booking;
use Illuminate\Http\Request;

/**
 * The provider abstraction. Implement per gateway (Stripe, Razorpay, …),
 * register in BookingServiceProvider, select via
 * BookingSettings::payment_provider. The workflow never knows which
 * provider is active.
 */
interface PaymentProviderInterface
{
    /** Stable identifier used in settings and webhook URLs (snake_case). */
    public function key(): string;

    /**
     * Create the payment on the provider side for the given reference.
     *
     * @throws BookingException
     */
    public function createPayment(Booking $booking, string $reference): PaymentIntentData;

    /**
     * Issue a refund at the provider.
     *
     * @throws BookingException when the provider rejects the refund
     */
    public function refund(Booking $booking): void;

    /**
     * Verify authenticity and normalize an incoming webhook.
     *
     * @throws InvalidPaymentWebhookException when the request cannot be trusted
     */
    public function parseWebhook(Request $request): PaymentWebhookData;

    /**
     * Whether this provider is enabled AND its credentials pass format
     * validation. Never makes a network call — a random/malformed
     * credential must be rejected here, before any checkout is
     * attempted, not discovered later as a failed gateway call.
     */
    public function isConfigured(): bool;

    /**
     * Currency codes (ISO 4217) this provider will accept an order for.
     *
     * @return list<string>
     */
    public function supportedCurrencies(): array;

    /**
     * Gateway-neutral checkout payload for the frontend: never a secret,
     * always safe to return in an API/Livewire response. Shape varies by
     * provider (Razorpay: order_id + key_id; Stripe: payment_intent_id +
     * client_secret + publishable key) but every provider includes
     * `provider`, `amount_minor`, and `currency`.
     *
     * @return array<string, mixed>
     *
     * @throws BookingException when no pending payment exists for the booking
     */
    public function checkoutPayload(Booking $booking): array;
}
