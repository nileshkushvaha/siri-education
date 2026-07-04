<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Registry\PaymentProviderRegistry;
use App\Models\Booking;
use App\Settings\BookingSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provider-agnostic payment workflow + booking/payment status sync:
 *
 *   reservation (pending/pending) → initiate → provider
 *   success  → paid   + reservation cleared + auto-confirmable → Confirmed
 *   failure  → failed + reservation holds until expiry (retry allowed)
 *   refund   → refunded + active booking cancelled
 *   cancel paid booking → refund (via SyncPaymentOnCancellation)
 */
final class BookingPaymentService implements BookingPaymentServiceInterface
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
        private readonly BookingServiceInterface $bookingService,
        private readonly PaymentProviderRegistry $providers,
        private readonly BookingSettings $settings,
    ) {}

    public function initiate(Booking $booking): PaymentIntentData
    {
        if (! $booking->payment_status->isPayable()) {
            throw new BookingException(sprintf(
                'Booking %s does not await payment (status: %s).',
                $booking->reference,
                $booking->payment_status->label(),
            ));
        }

        $reference = $booking->payment_reference ?? 'PAY-'.strtoupper(Str::random(12));

        // A retry after failure goes back to pending with the same reference.
        $booking = $this->bookings->updatePaymentStatus($booking, BookingPaymentStatus::Pending, $reference);

        return $this->provider()->createPayment($booking, $reference);
    }

    public function markPaid(Booking $booking, string $reference): Booking
    {
        $this->assertReference($booking, $reference, expected: BookingPaymentStatus::Pending);

        // Atomic: settle + release hold + confirm succeed or fail together,
        // so a crash can never leave a paid-but-still-reserved booking.
        return DB::transaction(function () use ($booking, $reference): Booking {
            $booking = $this->bookings->updatePaymentStatus($booking, BookingPaymentStatus::Paid, $reference);
            $booking = $this->bookings->clearReservation($booking);

            $this->logPayment($booking, BookingPaymentStatus::Paid, ['payment_reference' => $reference]);

            // Sync: a paid reservation confirms itself unless the type needs approval.
            if ($booking->status === BookingStatus::Pending && ! $booking->type->requires_approval) {
                $booking = $this->bookingService->confirm($booking);
            }

            return $booking;
        });
    }

    public function markFailed(Booking $booking, string $reference, ?string $reason = null): Booking
    {
        $this->assertReference($booking, $reference, expected: BookingPaymentStatus::Pending);

        $booking = $this->bookings->updatePaymentStatus($booking, BookingPaymentStatus::Failed, $reference);

        $this->logPayment($booking, BookingPaymentStatus::Failed, array_filter(['reason' => $reason]));

        return $booking;
    }

    public function refund(Booking $booking, ?string $reason = null): Booking
    {
        $this->assertPaid($booking);

        $this->provider()->refund($booking);

        return $this->recordRefund($booking, $reason);
    }

    public function recordRefund(Booking $booking, ?string $reason = null): Booking
    {
        $this->assertPaid($booking);

        return DB::transaction(function () use ($booking, $reason): Booking {
            $booking = $this->bookings->updatePaymentStatus($booking, BookingPaymentStatus::Refunded);

            $this->logPayment($booking, BookingPaymentStatus::Refunded, array_filter(['reason' => $reason]));

            // Sync: a refunded booking cannot stay bookable.
            if (! $booking->status->isTerminal()) {
                $booking = $this->bookingService->cancel($booking, new CancelBookingData(
                    BookingActor::forUser(Auth::user(), $booking),
                    $reason ?? 'Payment refunded',
                ));
            }

            return $booking;
        });
    }

    private function provider(): PaymentProviderInterface
    {
        return $this->providers->get($this->settings->payment_provider);
    }

    private function assertReference(Booking $booking, string $reference, BookingPaymentStatus $expected): void
    {
        if ($booking->payment_status !== $expected) {
            throw new BookingException(sprintf(
                'Booking %s is not in the "%s" payment state.',
                $booking->reference,
                $expected->label(),
            ));
        }

        if ($booking->payment_reference === null || ! hash_equals($booking->payment_reference, $reference)) {
            throw new BookingException('Payment reference does not match this booking.');
        }
    }

    private function assertPaid(Booking $booking): void
    {
        if ($booking->payment_status !== BookingPaymentStatus::Paid) {
            throw new BookingException(sprintf('Booking %s is not paid — nothing to refund.', $booking->reference));
        }
    }

    /** @param array<string, mixed> $meta */
    private function logPayment(Booking $booking, BookingPaymentStatus $to, array $meta = []): void
    {
        $this->bookings->logActivity(
            $booking,
            BookingActivityAction::PaymentStatusChanged,
            BookingActor::forUser(Auth::user(), $booking),
            Auth::id(),
            meta: ['payment_status' => $to->value, ...$meta],
        );
    }
}
