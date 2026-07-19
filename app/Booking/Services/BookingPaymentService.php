<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CancellationRefundDecision;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentStatusResult;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Events\BookingPaymentSucceeded;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Support\Financial\CurrencyEligibilityPolicy;
use App\Support\Financial\Exceptions\CurrencyNotUsableException;
use App\Support\Financial\FinancialOperation;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
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
 *   late success on a terminal booking → Option B (see handleLateTerminalPayment())
 */
final class BookingPaymentService implements BookingPaymentServiceInterface
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
        private readonly BookingServiceInterface $bookingService,
        private readonly PaymentProviderResolver $providers,
        private readonly AuditTrailService $audit,
        private readonly WalletService $wallets,
        private readonly WalletLedgerService $walletLedger,
        private readonly CurrencyEligibilityPolicy $currencyEligibility,
    ) {}

    public function initiate(Booking $booking): PaymentIntentData
    {
        // A booking can go terminal (cancelled/expired reservation) while
        // payment_status is still Pending — CancelBookingAction never
        // touches payment_status (see handleLateTerminalPayment()). Without
        // this check, a student could still initiate a *new* payment order
        // for a booking that can no longer be confirmed; Option B would
        // recover the money as a wallet credit if they went through with
        // it, but that is a bad-UX safety net, not a substitute for
        // blocking the attempt up front.
        if ($booking->status->isTerminal()) {
            throw new BookingException(sprintf(
                'Booking %s is %s and cannot accept a new payment.',
                $booking->reference,
                $booking->status->label(),
            ));
        }

        if (! $booking->payment_status->isPayable()) {
            throw new BookingException(sprintf(
                'Booking %s does not await payment (status: %s).',
                $booking->reference,
                $booking->payment_status->label(),
            ));
        }

        // Locked claim: two concurrent initiate() calls for the same
        // booking (double-click, retried request) must agree on one
        // reference. Reading payment_reference unlocked (the previous
        // behavior) let both racers see null, mint their own random
        // reference, and each successfully create its own BookingPayment
        // row — a real duplicate-attempt bug a genuine MySQL race
        // surfaces (Phase 16C concurrency tests). Locking the row before
        // reading forces the loser to observe the winner's committed
        // reference and reuse it instead.
        //
        // Phase 24M — GAP-031 Step 9: the Currency row is ALSO locked
        // here (inside the same transaction), so a concurrent admin
        // disable is serialized against this decision — whichever
        // commits first wins; the loser observes the other's
        // already-committed state. A stale browser page cannot initiate
        // payment after an admin disables the currency: this re-checks
        // Active status at the final internal boundary, never relying
        // on the booking's earlier price-resolution check alone.
        $booking = DB::transaction(function () use ($booking): Booking {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            try {
                $this->currencyEligibility->assertUsable((string) $locked->currency, FinancialOperation::NewInitiation, lock: true);
            } catch (CurrencyNotUsableException $e) {
                throw new BookingException($e->getMessage());
            }

            $reference = $locked->payment_reference ?? 'PAY-'.strtoupper(Str::random(12));

            // A retry after failure goes back to pending with the same reference.
            return $this->bookings->updatePaymentStatus($locked, BookingPaymentStatus::Pending, $reference);
        });

        return $this->provider($booking)->createPayment($booking, (string) $booking->payment_reference);
    }

    public function markPaid(Booking $booking, string $reference): Booking
    {
        // Locked, re-validated read: the webhook controller and
        // applyProviderStatus() (reconciliation/retry-verification) can
        // both reach markPaid() for the SAME booking concurrently.
        // assertReference() checking the caller-supplied $booking object
        // (fetched before either process started) let two racers both
        // observe "still Pending" and both proceed — locking the row
        // here and re-reading from it closes that gap; the loser now
        // reliably sees the winner's already-committed transition.
        [$path, $booking] = DB::transaction(function () use ($booking, $reference): array {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $this->assertReference($locked, $reference, expected: BookingPaymentStatus::Pending);

            // A booking can go terminal (cancelled/expired/completed/no_show)
            // while its payment_status is still Pending — CancelBookingAction
            // never touches payment_status, so a genuinely authentic,
            // signature-verified gateway success can still arrive here after
            // the booking itself no longer represents a lesson anyone can
            // attend. Option B (Phase 10.2B): the charge is real, so it is
            // preserved and redirected to the student's wallet rather than
            // rejected outright or silently confirming a dead booking.
            if ($locked->status->isTerminal()) {
                return ['late_terminal', $this->handleLateTerminalPayment($locked, $reference)];
            }

            $locked = $this->bookings->updatePaymentStatus($locked, BookingPaymentStatus::Paid, $reference);
            $locked = $this->bookings->clearReservation($locked);

            $this->logPayment($locked, BookingPaymentStatus::Paid, ['payment_reference' => $reference]);

            // Sync: a paid reservation confirms itself unless the type needs approval.
            if ($locked->status === BookingStatus::Pending && ! $locked->type->requires_approval) {
                $locked = $this->bookingService->confirm($locked);
            }

            return ['normal', $locked];
        });

        // After commit only — and never for Option B's late-terminal path,
        // which returned above without settling this booking. A duplicate
        // webhook cannot re-fire this: assertReference() already rejected
        // any delivery that finds payment_status !== Pending.
        if ($path === 'normal') {
            BookingPaymentSucceeded::dispatch($booking);
        }

        return $booking;
    }

    /**
     * The single financial-effect path a fetchStatus() poll (manual
     * "retry verification", scheduled reconciliation sweep) is ever
     * allowed to apply — deliberately reuses markPaid()/markFailed(),
     * never a separate settlement code path, so reconciliation and
     * webhook processing can never disagree about what "success" does
     * (same discipline as InstructorPayoutExecutionService::applyProviderStatus()
     * on the payout side).
     *
     * Idempotent: a payment row already in a terminal status is left
     * untouched (only last_synced_at advances) — reconciliation
     * confirms an already-settled outcome, it never re-applies one. A
     * markPaid()/markFailed() call losing a race to a webhook that
     * settled the same booking first is swallowed the same way the
     * webhook controller itself swallows it (BookingException from
     * assertReference() means "already handled," not a real failure).
     */
    public function applyProviderStatus(BookingPayment $payment, PaymentStatusResult $status): BookingPayment
    {
        $payment = BookingPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        if ($payment->status->isTerminal()) {
            $payment->forceFill(['last_synced_at' => now()])->save();

            return $payment;
        }

        $payment->forceFill([
            'status' => $status->recordStatus,
            'provider_payment_id' => $status->providerPaymentId ?? $payment->provider_payment_id,
            'last_synced_at' => now(),
            ...($status->recordStatus === BookingPaymentRecordStatus::Captured ? ['paid_at' => now()] : []),
            ...($status->recordStatus === BookingPaymentRecordStatus::Failed ? ['failed_at' => now()] : []),
        ])->save();

        $booking = $this->bookings->find($payment->booking_id);

        if ($booking !== null && $status->recordStatus === BookingPaymentRecordStatus::Captured) {
            try {
                $this->markPaid($booking, $payment->idempotency_key);
            } catch (BookingException) {
                // Already settled by a concurrent webhook — no-op.
            }
        } elseif ($booking !== null && $status->recordStatus === BookingPaymentRecordStatus::Failed) {
            try {
                $this->markFailed($booking, $payment->idempotency_key, $status->safeReason);
            } catch (BookingException) {
                // Already settled by a concurrent webhook — no-op.
            }
        }

        return BookingPayment::query()->whereKey($payment->id)->firstOrFail();
    }

    public function markFailed(Booking $booking, string $reference, ?string $reason = null): Booking
    {
        // Same locked-read discipline as markPaid() — see its docblock.
        return DB::transaction(function () use ($booking, $reference, $reason): Booking {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $this->assertReference($locked, $reference, expected: BookingPaymentStatus::Pending);

            $locked = $this->bookings->updatePaymentStatus($locked, BookingPaymentStatus::Failed, $reference);

            $this->logPayment($locked, BookingPaymentStatus::Failed, array_filter(['reason' => $reason]));

            return $locked;
        });
    }

    /**
     * Version 1 refund policy (Phase 16A.1): the default, normal-path
     * refund never touches the gateway. It locks the booking (the
     * serialization point shared with refundViaProvider — whichever
     * path's transaction commits first wins; the loser sees
     * payment_status no longer Paid or the resolution already tagged),
     * credits the student's wallet in the payment's original currency,
     * and finalizes in the same transaction — there is no external
     * call here to hold the lock open for.
     */
    public function refundToWallet(Booking $booking, ?string $reason = null, ?CancellationRefundDecision $decision = null): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $decision): Booking {
            $booking = $this->lockedPaidBooking($booking);
            $payment = $this->lockedUnresolvedCapturedPayment($booking);

            $student = $booking->student;
            // tryCreditWalletForRefund() mutates $payment's own metadata
            // (wallet_ledger_entry_id) on success before we add the
            // resolution tag here, so it is never overwritten below.
            $credited = $student !== null && $this->tryCreditWalletForRefund($booking, $payment, $student);
            $decisionMeta = $decision?->toMeta() ?? [];

            $payment->forceFill([
                'metadata' => $credited
                    ? [...($payment->metadata ?? []), 'refund_resolution' => 'wallet_credited', 'refund_reason' => $reason, ...$decisionMeta]
                    : [
                        ...($payment->metadata ?? []),
                        'refund_resolution' => 'manual_resolution_required',
                        'manual_resolution_required' => true,
                        'manual_resolution_reason' => $student === null
                            ? 'Guest booking has no user account to hold a wallet credit — use refundViaProvider() or resolve manually.'
                            : 'Automatic wallet credit failed — needs manual admin/support resolution.',
                        'refund_reason' => $reason,
                        ...$decisionMeta,
                    ],
            ])->save();

            return $this->finalizeRefundedBooking($booking, $reason);
        });
    }

    /**
     * Phase 24C — SRS 11.24/6.8: a late student cancellation is not
     * refund-eligible. The booking is already Cancelled by the time
     * this runs (BookingService::cancel() already committed); this
     * only records the frozen decision on the payment for
     * traceability. payment_status deliberately stays Paid — the
     * platform retained the charge, nothing was refunded, so
     * "Refunded" would misrepresent what happened (and would
     * incorrectly count against revenue in reporting). No ledger
     * entry, no gateway call. lockedUnresolvedCapturedPayment() is the
     * same idempotency guard refundToWallet() relies on: a duplicate
     * event delivery finds the payment already resolved and no-ops.
     */
    public function recordIneligibleCancellation(Booking $booking, CancellationRefundDecision $decision): Booking
    {
        return DB::transaction(function () use ($booking, $decision): Booking {
            $booking = $this->lockedPaidBooking($booking);
            $payment = $this->lockedUnresolvedCapturedPayment($booking);

            $meta = ['refund_resolution' => 'not_eligible_late_cancellation', ...$decision->toMeta()];

            $payment->forceFill(['metadata' => [...($payment->metadata ?? []), ...$meta]])->save();

            $this->bookings->logActivity($booking, BookingActivityAction::PaymentStatusChanged, BookingActor::System, meta: $meta);
            $this->audit->logSystem(
                'payments',
                'cancellation_not_refunded',
                sprintf('Booking %s cancelled outside the refund window; no refund issued.', $booking->reference),
                $booking,
                $meta,
            );

            return $booking;
        });
    }

    /** @throws never — see tryCreditStudentWallet()'s docblock for why every failure mode is caught here too. */
    private function tryCreditWalletForRefund(Booking $booking, BookingPayment $payment, User $student): bool
    {
        try {
            $wallet = $this->wallets->getOrCreateWalletForExistingObligation($student, $payment->currency_code);

            $entry = $this->walletLedger->credit(
                $wallet,
                $payment->amount_minor,
                WalletLedgerEntryType::Refund,
                $student,
                idempotencyKey: sprintf('cancellation-refund:%s', $payment->id),
                description: sprintf('Booking %s cancelled; amount credited to wallet.', $booking->reference),
                sourceType: BookingPayment::class,
                sourceId: (string) $payment->id,
            );
        } catch (\Throwable) {
            return false;
        }

        $payment->forceFill(['metadata' => [...($payment->metadata ?? []), 'wallet_ledger_entry_id' => $entry->id]])->save();

        return true;
    }

    /**
     * Exception-path refund. The gateway call happens with no database
     * lock held (§16 of the Phase 16A.1 routing audit — the same rule
     * as instructor payout execution): a short transaction claims the
     * payment first, the network call happens outside it, then a
     * second short transaction finalizes.
     */
    public function refundViaProvider(Booking $booking, User $actor, string $reason): Booking
    {
        if (trim($reason) === '') {
            throw new BookingException('A reason is required for a direct provider refund.');
        }

        $payment = DB::transaction(function () use ($booking): BookingPayment {
            $booking = $this->lockedPaidBooking($booking);
            $payment = $this->lockedUnresolvedCapturedPayment($booking);

            $payment->forceFill([
                'metadata' => [...($payment->metadata ?? []), 'refund_resolution' => 'provider_refund_pending'],
            ])->save();

            return $payment;
        });

        try {
            $this->provider()->refund($booking);
        } catch (\Throwable $e) {
            // The claim already committed — clear it so a retry (or the
            // wallet-credit path) is not permanently locked out by a
            // provider call that never actually moved money.
            DB::transaction(function () use ($payment): void {
                $payment = BookingPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $metadata = $payment->metadata ?? [];
                unset($metadata['refund_resolution']);
                $payment->forceFill(['metadata' => $metadata])->save();
            });

            throw $e;
        }

        return DB::transaction(function () use ($booking, $payment, $actor, $reason): Booking {
            $payment = BookingPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $payment->forceFill([
                'metadata' => [
                    ...($payment->metadata ?? []),
                    'refund_resolution' => 'provider_refunded',
                    'refund_reason' => $reason,
                    'refunded_by' => $actor->id,
                ],
            ])->save();

            $booking = $this->finalizeRefundedBooking($booking, $reason);

            $this->audit->logOverride(
                $actor,
                'payments',
                'payment_refunded_via_provider',
                sprintf('Booking %s payment refunded directly via provider.', $booking->reference),
                $reason,
                $booking,
            );

            return $booking;
        });
    }

    public function recordRefund(Booking $booking, ?string $reason = null): Booking
    {
        $this->assertPaid($booking);

        return DB::transaction(function () use ($booking, $reason): Booking {
            // Best-effort resolution tag for dashboard consistency — this
            // path is a provider-initiated notification (e.g. a dashboard
            // refund), so a matching captured row may already be tagged
            // by refundViaProvider(), already resolved, or (in older
            // fixtures/tests) absent entirely; none of those block the
            // booking-status sync below, which is this method's contract.
            $payment = BookingPayment::query()
                ->where('booking_id', $booking->id)
                ->where('status', BookingPaymentRecordStatus::Captured)
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($payment !== null && ($payment->metadata['refund_resolution'] ?? null) === null) {
                $payment->forceFill(['metadata' => [...($payment->metadata ?? []), 'refund_resolution' => 'provider_refunded_externally']])->save();
            }

            return $this->finalizeRefundedBooking($booking, $reason);
        });
    }

    /** Shared status-sync tail for every refund path: Refunded + cancel if still active. */
    private function finalizeRefundedBooking(Booking $booking, ?string $reason): Booking
    {
        $booking = $this->bookings->updatePaymentStatus($booking, BookingPaymentStatus::Refunded);

        $this->logPayment($booking, BookingPaymentStatus::Refunded, array_filter(['reason' => $reason]));

        if (! $booking->status->isTerminal()) {
            $booking = $this->bookingService->cancel($booking, new CancelBookingData(
                BookingActor::forUser(Auth::user(), $booking),
                $reason ?? 'Payment refunded',
            ));
        }

        return $booking;
    }

    private function lockedPaidBooking(Booking $booking): Booking
    {
        $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

        if ($booking->payment_status !== BookingPaymentStatus::Paid) {
            throw new BookingException(sprintf('Booking %s is not paid — nothing to refund.', $booking->reference));
        }

        return $booking;
    }

    /** The same amount can never be refunded to both wallet and provider — this is the guard. */
    private function lockedUnresolvedCapturedPayment(Booking $booking): BookingPayment
    {
        $payment = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->where('status', BookingPaymentRecordStatus::Captured)
            ->latest('created_at')
            ->lockForUpdate()
            ->first();

        if ($payment === null) {
            throw new BookingException(sprintf('Booking %s has no captured payment to refund.', $booking->reference));
        }

        if (($payment->metadata['refund_resolution'] ?? null) !== null) {
            throw new BookingException(sprintf('Booking %s payment was already resolved (%s).', $booking->reference, $payment->metadata['refund_resolution']));
        }

        return $payment;
    }

    /**
     * Gateway-neutral frontend checkout payload for a booking that has
     * already called initiate(). Never returns a secret — see each
     * provider's checkoutPayload() for its exact (provider-specific)
     * shape.
     *
     * @return array<string, mixed>
     *
     * @throws BookingException when the configured provider cannot be used, or no pending payment exists
     */
    public function checkoutPayload(Booking $booking): array
    {
        return $this->provider($booking)->checkoutPayload($booking);
    }

    /**
     * Country-aware provider selection (Phase 10.2B): resolves the
     * payer's country from the student's profile when one exists and
     * lets PaymentProviderResolver apply its routing order
     * (Country::payment_routing → default_provider → legacy
     * BookingSettings::payment_provider). Passing null (the $booking-less
     * call form) preserves the exact pre-10.2B behavior.
     */
    private function provider(?Booking $booking = null): PaymentProviderInterface
    {
        return $this->providers->current($booking !== null ? $this->resolveCountryIso2($booking) : null);
    }

    private function resolveCountryIso2(Booking $booking): ?string
    {
        return $booking->student?->profile?->country?->iso2;
    }

    /**
     * Option B (Phase 10.2B, replacing Phase 10.2's outright rejection):
     * the payment is authentic (signature, amount, and currency were
     * already verified by the provider before markPaid() was ever
     * called) but the booking can no longer be confirmed for it. The
     * charge is preserved, never silently discarded or left ambiguous:
     *
     *   - every booking has an authenticated student → credited to
     *     their wallet, exactly once (WalletLedgerService::credit()'s
     *     own idempotency key guards a second delivery of the same
     *     event; the already-terminal booking_payments row check below
     *     is the first, cheaper guard).
     *   - wallet credit itself fails (e.g. the student's wallet was
     *     administratively closed) → falls back to the same
     *     manual-resolution flag rather than losing track of the money
     *     or raising an uncaught exception through the webhook
     *     controller (WalletException is not a BookingException and
     *     would otherwise surface as a raw 500).
     *
     * Never: confirms the booking, clears its reservation, creates a
     * meeting, or marks it Paid (Paid means "this booking's charge is
     * good and this booking is going ahead" — neither is true here).
     * `Booking.payment_status` becomes Refunded only when money was
     * actually redirected to a wallet — the closest existing enum
     * value to "this charge was not retained as this booking's
     * revenue" — and is left untouched (Pending) whenever nothing was
     * actually resolved yet, so the state never claims more than what
     * happened.
     */
    private function handleLateTerminalPayment(Booking $booking, string $reference): Booking
    {
        return DB::transaction(function () use ($booking, $reference): Booking {
            $payment = BookingPayment::query()
                ->where('booking_id', $booking->id)
                ->where('idempotency_key', $reference)
                ->lockForUpdate()
                ->first();

            if ($payment !== null && ($payment->metadata['late_terminal_handled'] ?? false) === true) {
                // Already processed by an earlier delivery of this event.
                return $booking;
            }

            if ($payment === null) {
                // No known payment attempt to safely attribute an amount
                // to — do not guess, do not credit, do not mutate status.
                $this->logLateTerminalEvent($booking, 'payment_late_terminal_unattributed', [
                    'booking_status' => $booking->status->value,
                ]);

                return $booking;
            }

            $payment->forceFill([
                'metadata' => [...($payment->metadata ?? []), 'late_terminal_handled' => true],
            ])->save();

            $credited = $this->tryCreditStudentWallet($booking, $payment, $reference);

            if ($credited) {
                $booking = $this->bookings->updatePaymentStatus($booking, BookingPaymentStatus::Refunded, $reference);

                $this->logPayment($booking, BookingPaymentStatus::Refunded, [
                    'late_terminal' => true,
                    'wallet_credited' => true,
                ]);

                return $booking;
            }

            $payment->forceFill([
                'metadata' => [
                    ...($payment->metadata ?? []),
                    'manual_resolution_required' => true,
                    'manual_resolution_reason' => 'Automatic wallet credit failed — needs manual admin/support resolution.',
                ],
            ])->save();

            $this->logLateTerminalEvent($booking, 'payment_late_terminal_manual_resolution', [
                'booking_status' => $booking->status->value,
            ]);

            return $booking;
        });
    }

    /**
     * @throws never — any failure (WalletException for a closed wallet,
     *               ValidationException from WalletService::resolveCurrency()
     *               when the booking's currency has no active
     *               Currency row, or anything else) is caught and
     *               converted to a "not credited" result so the
     *               caller falls back to manual resolution instead of
     *               an uncaught exception reaching the webhook
     *               controller as a raw 500 — the wallet subsystem's
     *               failure modes are heterogeneous and not all of
     *               them extend WalletException.
     */
    private function tryCreditStudentWallet(Booking $booking, BookingPayment $payment, string $reference): bool
    {
        $student = $booking->student;

        if ($student === null) {
            return false;
        }

        try {
            $wallet = $this->wallets->getOrCreateWalletForExistingObligation($student, $payment->currency_code);

            $entry = $this->walletLedger->credit(
                $wallet,
                $payment->amount_minor,
                WalletLedgerEntryType::LatePaymentCredit,
                $student,
                idempotencyKey: sprintf(
                    'late-payment-credit:%s:%s',
                    $payment->id,
                    $payment->provider_payment_id ?? $reference,
                ),
                description: 'Payment received after booking expiry/cancellation; credited to wallet.',
                sourceType: BookingPayment::class,
                sourceId: (string) $payment->id,
            );
        } catch (\Throwable) {
            return false;
        }

        $payment->forceFill([
            'metadata' => [...($payment->metadata ?? []), 'wallet_ledger_entry_id' => $entry->id],
        ])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function logLateTerminalEvent(Booking $booking, string $event, array $meta): void
    {
        $this->bookings->logActivity(
            $booking,
            BookingActivityAction::PaymentStatusChanged,
            BookingActor::System,
            meta: $meta,
        );

        $this->audit->logSystem(
            'payments',
            $event,
            sprintf('Late payment success on terminal booking %s (%s).', $booking->reference, $booking->status->label()),
            $booking,
            $meta,
        );
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

    /**
     * Writes to both the per-booking business timeline (booking_activities,
     * via BookingRepository::logActivity) and the unified, searchable
     * Activity Log (via AuditTrailService) — financial state changes must
     * be traceable centrally, not only from inside one booking's history.
     *
     * @param  array<string, mixed>  $meta
     */
    private function logPayment(Booking $booking, BookingPaymentStatus $to, array $meta = []): void
    {
        $this->bookings->logActivity(
            $booking,
            BookingActivityAction::PaymentStatusChanged,
            BookingActor::forUser(Auth::user(), $booking),
            Auth::id(),
            meta: ['payment_status' => $to->value, ...$meta],
        );

        $description = sprintf('Booking %s payment %s', $booking->reference, $to->label());
        $properties = ['payment_status' => $to->value, ...$meta];

        if ($user = Auth::user()) {
            $this->audit->logUser($user, 'payments', 'payment_'.$to->value, $description, $booking, $properties);

            return;
        }

        $this->audit->logSystem('payments', 'payment_'.$to->value, $description, $booking, $properties);
    }
}
