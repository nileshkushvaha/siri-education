<?php

declare(strict_types=1);

namespace App\Booking\Support;

use App\Booking\Services\BookingPaymentSettlementService;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Payment;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Payments\Services\PaymentWebhookEventParser;
use Illuminate\Support\Str;

/**
 * The local/testing "Simulate success / Simulate failure" buttons.
 *
 * These used to call BookingPaymentService::markPaid() directly. That
 * confirmed the booking but left the obligation and the Payment attempt
 * Pending, because only BookingPaymentSettlementService captures them —
 * and the invoice/notification listeners resolve a CAPTURED obligation.
 * The result was a booking that looked confirmed while silently
 * producing no receipt and no notifications: a state real settlement can
 * never produce, which made local verification actively misleading.
 *
 * It now drives the SAME settlement service a signed webhook does, with
 * a VerifiedPaymentEvent built from the booking's own open attempt. A
 * simulated payment therefore exercises the real path end to end —
 * capture, invoice, student and instructor notifications included.
 *
 * Environment is re-checked here rather than trusted from the caller:
 * the buttons not rendering in production is a UX nicety, not the safety
 * boundary.
 */
final class FakePaymentSimulator
{
    public function __construct(
        private readonly BookingPaymentSettlementService $settlement,
    ) {}

    public function isAvailable(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * @return bool whether a settlement/failure was actually applied
     */
    public function simulate(Booking $booking, bool $success): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $attempt = $this->openAttemptFor($booking);

        if ($attempt === null) {
            return false;
        }

        return $this->settlement->settle($attempt, new VerifiedPaymentEvent(
            provider: (string) $attempt->provider,
            type: $success ? PaymentEventType::Succeeded : PaymentEventType::Failed,
            reference: (string) $attempt->idempotency_key,
            providerOrderId: $attempt->provider_order_id,
            // A real provider always names the payment it settled; without
            // one the ledger would record a capture with no provider
            // identity, which is not a shape production can produce.
            providerPaymentId: $attempt->provider_payment_id ?? 'sim_'.Str::lower(Str::random(14)),
            amountMinor: (int) $attempt->amount_minor,
            currencyCode: (string) $attempt->currency_code,
            reason: $success ? null : 'Simulated failure (fake provider).',
        ));
    }

    /**
     * The booking's open attempt — only ever the fake provider's.
     * Simulating a settlement against a real Razorpay/Stripe attempt
     * would fabricate money that no gateway actually collected.
     */
    private function openAttemptFor(Booking $booking): ?Payment
    {
        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->first();

        if ($obligation === null) {
            return null;
        }

        return Payment::query()
            ->forPayable(BookingPayment::PAYABLE_TYPE, (string) $obligation->getKey())
            ->where('provider', PaymentWebhookEventParser::FAKE_PROVIDER)
            ->open()
            ->latest('created_at')
            ->first();
    }
}
