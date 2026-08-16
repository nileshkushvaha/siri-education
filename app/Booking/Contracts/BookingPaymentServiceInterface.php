<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\CancellationRefundDecision;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentStatusResult;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\User;

/**
 * Payment workflow for paid bookings. Provider-agnostic: gateway calls
 * go through PaymentProviderInterface (BookingSettings picks which).
 * Booking status stays synchronized: success confirms reserved
 * bookings, cancellation of a paid booking triggers a refund
 * (SyncPaymentOnCancellation).
 *
 * Refund policy: the normal path never touches the gateway —
 * `refundToWallet()` credits the student's
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
     * Pays for the booking directly from the student's own wallet
     * balance — a synchronous, self-contained alternative to gateway
     * checkout with no redirect, webhook, or reconciliation lifecycle.
     * $student must be the booking's own student; no admin-on-behalf-of
     * path exists for this method. Debits the wallet and finalizes the
     * booking/payment atomically — either both happen or neither does.
     *
     * @throws BookingException when the booking does not await payment, the actor is not the booking's own student,
     *                          the wallet feature is disabled, the currency is not usable, or the wallet balance
     *                          is insufficient
     */
    public function payWithWallet(Booking $booking, User $student): Booking;

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
     * $decision, when supplied, is recorded as safe metadata on the
     * payment for audit-traceability — it never
     * changes whether this method credits the wallet; the caller
     * (SyncPaymentOnCancellation) only invokes this method at all when
     * the decision was already eligible.
     *
     * @throws BookingException when the booking is not paid, or the payment cannot be attributed to a user
     */
    public function refundToWallet(Booking $booking, ?string $reason = null, ?CancellationRefundDecision $decision = null): Booking;

    /**
     * A paid booking was cancelled but CancellationRefundPolicy decided
     * the cancellation is not refund-eligible (a late student
     * cancellation). Records the frozen decision as an auditable
     * no-refund disposition on the payment — no wallet ledger entry,
     * no payment_status change (it stays Paid: the platform retains
     * the charge), no gateway call. Idempotent — a duplicate delivery
     * of the same event is a no-op once resolved.
     *
     * @throws BookingException when the booking is not paid
     */
    public function recordIneligibleCancellation(Booking $booking, CancellationRefundDecision $decision): Booking;

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
     * HAS NO PRODUCTION CALLER as of Version 1, and deliberately no
     * synthetic one. Refund webhooks are not consumed
     * (PaymentWebhookEventParser reads `payload.payment.entity`; a
     * Razorpay `refund.processed` carries `payload.refund.entity`), so
     * dashboard-issued refunds are an operations constraint rather than
     * a supported flow — see docs/financial-domain-architecture.md §1.7.
     * Retained as the entry point a future refund-event handler calls,
     * because it already owns the transactional pairing (mark refunded
     * + cancel the booking) such a handler must not re-derive.
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

    /**
     * The single financial-effect path a fetchStatus() poll (manual
     * "retry verification", scheduled reconciliation sweep) is ever
     * allowed to apply — reuses markPaid()/markFailed() internally, so
     * reconciliation and webhook processing can never disagree about
     * what "success" does. Idempotent: a payment already in a terminal
     * status is left untouched (only its last-synced timestamp advances).
     */
    public function applyProviderStatus(BookingPayment $payment, PaymentStatusResult $status): BookingPayment;
}
