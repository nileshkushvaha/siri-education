<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\PaymentIntentData;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;

/**
 * Payment workflow for paid bookings. Provider-agnostic: gateway calls
 * go through PaymentProviderInterface (BookingSettings picks which).
 * Booking status stays synchronized: success confirms reserved
 * bookings, cancellation of a paid booking triggers a refund
 * (SyncPaymentOnCancellation).
 *
 * Refund policy (Phase 16A.1, "Version 1"): the normal path never
 * touches the gateway — `refundToWallet()` credits the student's
 * wallet and is what SyncPaymentOnCancellation always calls. A direct
 * gateway refund (`refundViaProvider()`) is a separately-permissioned
 * exception action, never the default, and the two are mutually
 * exclusive per payment — `BookingPayment.metadata['refund_resolution']`
 * is the guard.
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
     * The default, normal-path refund: credits the student's wallet in
     * the payment's original currency, never calls the gateway. Used
     * by every automatic cancellation flow. Idempotent — a duplicate
     * call (e.g. a retried queued listener) has no additional effect.
     *
     * @throws BookingException when the booking is not paid, or the payment cannot be attributed to a user
     */
    public function refundToWallet(Booking $booking, ?string $reason = null): Booking;

    /**
     * Exception-path refund: calls the real gateway directly. Never
     * the default — reserved for duplicate charges, payments collected
     * without a valid obligation, compliance/legal requirements, or
     * finance-admin correction. Requires the acting user and a
     * mandatory reason; mutually exclusive with `refundToWallet()` for
     * the same payment.
     *
     * @throws BookingException when the booking is not paid, or this payment was already resolved
     */
    public function refundViaProvider(Booking $booking, User $actor, string $reason): Booking;

    /**
     * Record a refund that already happened provider-side (webhook) —
     * e.g. a dashboard-initiated refund reported asynchronously. No
     * money moves here; this only synchronizes local state.
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
