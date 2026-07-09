<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\PaymentIntentData;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;

/**
 * Payment workflow for paid bookings. Provider-agnostic: gateway calls
 * go through PaymentProviderInterface (BookingSettings picks which).
 * Booking status stays synchronized: success confirms reserved
 * bookings, refunds cancel active ones, cancellation of a paid
 * booking triggers a refund (SyncPaymentOnCancellation).
 */
interface BookingPaymentServiceInterface
{
    /** @throws BookingException when the booking does not await payment */
    public function initiate(Booking $booking): PaymentIntentData;

    /**
     * Payment succeeded — settle and confirm the reservation.
     *
     * @throws BookingException when the reference does not match
     */
    public function markPaid(Booking $booking, string $reference): Booking;

    /**
     * Payment failed — record it; the reservation holds until expiry
     * so the payer may retry.
     *
     * @throws BookingException when the reference does not match
     */
    public function markFailed(Booking $booking, string $reference, ?string $reason = null): Booking;

    /**
     * Actively refund via the provider, then record it.
     *
     * @throws BookingException when the booking is not paid
     */
    public function refund(Booking $booking, ?string $reason = null): Booking;

    /**
     * Record a refund that already happened provider-side (webhook).
     *
     * @throws BookingException when the booking is not paid
     */
    public function recordRefund(Booking $booking, ?string $reason = null): Booking;

    /**
     * Gateway-neutral frontend checkout payload (never a secret) for the
     * currently configured provider.
     *
     * @return array<string, mixed>
     *
     * @throws BookingException when the provider cannot be used, or no pending payment exists
     */
    public function checkoutPayload(Booking $booking): array;
}
