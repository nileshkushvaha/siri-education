<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;

/**
 * Sends a booking refund to the provider that actually took the money.
 *
 * Provider identity is resolved from the SETTLED ATTEMPT, not from the
 * obligation. The obligation records what was owed; only the winning
 * Payment attempt knows which provider charge to reverse, and copying
 * that identifier back onto the obligation would be a denormalisation
 * that drifts the moment a second attempt exists.
 *
 * Refund POLICY is not here. Whether a cancellation refunds at all,
 * whether it goes to the provider or the student's wallet, and what
 * happens to package units — all of that stays in the Booking domain
 * (BookingPaymentService, CancellationRefundDecision). This service
 * only executes a provider refund once that decision has been made.
 */
final class BookingPaymentRefundService
{
    public function __construct(
        private readonly RazorpayGatewayClient $razorpay,
        private readonly StripeGatewayClient $stripe,
        private readonly PaymentGatewaySettings $settings,
    ) {}

    /**
     * Reverses the settled attempt for this booking.
     *
     * @throws BookingException when there is no single settled attempt to reverse, or the provider rejects it
     */
    public function refund(Booking $booking): void
    {
        $attempt = $this->settledAttemptFor($booking);

        match ($attempt->provider) {
            'razorpay' => $this->refundRazorpay($attempt),
            'stripe' => $this->refundStripe($attempt),
            default => throw new BookingException(sprintf(
                'Booking %s was paid through "%s", which cannot be refunded automatically.',
                $booking->reference,
                (string) $attempt->provider,
            )),
        };

        BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->update(['status' => BookingPaymentRecordStatus::Refunded->value]);
    }

    /**
     * Exactly one settled attempt must exist.
     *
     * The one-open-attempt invariant plus idempotent settlement make two
     * settled attempts for one obligation impossible, but this refuses
     * rather than guesses if that ever stops being true: refunding the
     * wrong charge is worse than refusing to refund automatically.
     *
     * @throws BookingException
     */
    private function settledAttemptFor(Booking $booking): Payment
    {
        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->first();

        if ($obligation === null) {
            throw new BookingException(sprintf('Booking %s has no payment obligation to refund.', $booking->reference));
        }

        $settled = $obligation->paymentAttempts()
            ->where('status', PaymentStatus::Paid->value)
            ->whereNotNull('provider_payment_id')
            ->get();

        if ($settled->count() > 1) {
            throw new BookingException(sprintf(
                'Booking %s has more than one settled payment attempt; refund it manually.',
                $booking->reference,
            ));
        }

        $attempt = $settled->first();

        if ($attempt === null) {
            throw new BookingException(sprintf('Booking %s has no captured payment to refund.', $booking->reference));
        }

        return $attempt;
    }

    private function refundRazorpay(Payment $attempt): void
    {
        try {
            $this->razorpay->refundPayment(
                (string) $this->settings->razorpay_key_id,
                (string) PaymentWebhookSignatureService::decryptSecret($this->settings, 'razorpay_key_secret'),
                (string) $attempt->provider_payment_id,
                ['amount' => (int) $attempt->amount_minor],
            );
        } catch (GatewayRequestException $e) {
            throw new BookingException('Razorpay refund failed: '.$e->getMessage());
        }
    }

    private function refundStripe(Payment $attempt): void
    {
        try {
            $this->stripe->createRefund(
                (string) PaymentWebhookSignatureService::decryptSecret($this->settings, 'stripe_secret_key'),
                [
                    'payment_intent' => (string) $attempt->provider_payment_id,
                    'amount' => (int) $attempt->amount_minor,
                ],
            );
        } catch (GatewayRequestException $e) {
            throw new BookingException('Stripe refund failed: '.$e->getMessage());
        }
    }
}
