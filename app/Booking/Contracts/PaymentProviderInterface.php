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
}
