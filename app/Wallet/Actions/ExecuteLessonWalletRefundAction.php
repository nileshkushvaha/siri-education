<?php

declare(strict_types=1);

namespace App\Wallet\Actions;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Enums\LessonStudentDisposition;
use App\Earnings\Exceptions\EarningException;
use App\Models\BookingPayment;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\AuditTrailService;
use App\Wallet\DTOs\LessonRefundExecutionResult;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Events\LessonRefundCompleted;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of lesson-outcome wallet refunds (Phase 17F).
 *
 * Wallet-only by design: gateway-paid lessons are credited to the
 * student wallet — no Stripe/Razorpay refund API is ever called and
 * the original payment record is preserved; wallet-paid lessons get a
 * separate refund credit and the original debit is never touched. All
 * balance changes flow through WalletLedgerService::credit(), whose
 * idempotency key ('lesson-refund:{disposition}:v{version}') makes
 * duplicate and concurrent execution return the one existing credit.
 *
 * Eligibility problems (missing/ambiguous charge, currency mismatch,
 * refund already completed) are DECISIONS — committed as manual review
 * with a reason. Infrastructure failures THROW — the transaction rolls
 * back and the disposition stays Ready for a retry, so it can never be
 * marked resolved without a completed ledger entry.
 */
final class ExecuteLessonWalletRefundAction
{
    private const string LOG_NAME = 'instructor_earnings';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletLedgerService $ledger,
        private readonly AuditTrailService $audit,
    ) {}

    /** @throws EarningException */
    public function execute(LessonFinancialDisposition $disposition, ?User $admin = null): LessonRefundExecutionResult
    {
        return DB::transaction(function () use ($disposition, $admin): LessonRefundExecutionResult {
            $disposition = LessonFinancialDisposition::query()
                ->whereKey($disposition->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent repeat — the refund already exists.
            if ($disposition->refund_ledger_entry_id !== null) {
                return new LessonRefundExecutionResult($disposition, $disposition->refundLedgerEntry, credited: false);
            }

            // Revalidate under the lock: only explicitly approved,
            // un-held, full-wallet-refund dispositions execute.
            if ($disposition->processing_status !== LessonFinancialDispositionStatus::Ready
                || $disposition->student_disposition !== LessonStudentDisposition::FullWalletRefundRequired
                || $disposition->admin_hold) {
                throw new EarningException('This disposition is not approved and ready for a wallet refund.');
            }

            $booking = $disposition->booking;
            $student = $booking?->student;

            if ($booking === null || $student === null) {
                return $this->defer($disposition, 'missing_student');
            }

            if (! in_array($booking->payment_status, [BookingPaymentStatus::Paid, BookingPaymentStatus::Refunded], strict: true)) {
                return $this->defer($disposition, 'booking_not_paid');
            }

            // The original successful charge — never current pricing.
            $captured = BookingPayment::query()
                ->where('booking_id', $booking->id)
                ->whereIn('status', [BookingPaymentRecordStatus::Captured, BookingPaymentRecordStatus::Refunded])
                ->lockForUpdate()
                ->get();

            if ($captured->isEmpty()) {
                return $this->defer($disposition, 'missing_original_charge');
            }

            if ($captured->count() > 1) {
                return $this->defer($disposition, 'ambiguous_charge_records');
            }

            $payment = $captured->first();

            // Never convert currency; an inconsistent snapshot is a human's call.
            if ($booking->currency !== null && $payment->currency_code !== $booking->currency) {
                return $this->defer($disposition, 'currency_mismatch');
            }

            // The cancellation pipeline may already have credited this
            // charge back — detect its idempotency key and never duplicate.
            $cancellationRefund = WalletLedgerEntry::query()
                ->where('idempotency_key', sprintf('cancellation-refund:%s', $payment->id))
                ->first();

            if ($cancellationRefund !== null) {
                return $this->settleAlreadyRefunded($disposition, $cancellationRefund);
            }

            if ($booking->payment_status === BookingPaymentStatus::Refunded) {
                // Refunded at the provider (no wallet credit to link) —
                // crediting the wallet on top would double-refund.
                return $this->defer($disposition, 'refund_already_completed');
            }

            // Credit through the existing wallet domain — full original
            // amount, original currency, never more, never converted.
            $actor = $admin ?? $disposition->resolver ?? $student;
            $wallet = $this->wallets->getOrCreateWalletForExistingObligation($student, $payment->currency_code);

            $entry = $this->ledger->credit(
                $wallet,
                $payment->amount_minor,
                WalletLedgerEntryType::Refund,
                $actor,
                idempotencyKey: sprintf('lesson-refund:%s:v%d', $disposition->id, $disposition->version),
                description: sprintf('Lesson outcome refund for booking %s (%s).', $booking->reference, $disposition->outcome->label()),
                sourceType: LessonFinancialDisposition::class,
                sourceId: (string) $disposition->id,
                metadata: [
                    'lesson_id' => $disposition->lesson_id,
                    'booking_id' => $disposition->booking_id,
                    'booking_reference' => $booking->reference,
                    'outcome' => $disposition->outcome->value,
                    'refund_reason_code' => $disposition->reason_code,
                    'source_payment_id' => $payment->id,
                    'source_payment_reference' => $payment->provider_payment_id ?? $booking->payment_reference,
                    'disposition_version' => $disposition->version,
                ],
            );

            $disposition->forceFill([
                'refund_ledger_entry_id' => $entry->id,
                'refund_executed_at' => now()->toImmutable()->utc(),
                'processing_status' => LessonFinancialDispositionStatus::Resolved,
                'reason_code' => 'refund_completed',
                'resolved_at' => now()->toImmutable()->utc(),
                'resolved_by' => $admin?->id ?? $disposition->resolved_by,
            ])->save();

            $this->auditExecution($disposition, $entry, $admin);

            LessonRefundCompleted::dispatch($disposition, $entry);

            return new LessonRefundExecutionResult($disposition, $entry, credited: true);
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** A decision, not a failure: park for a human with the reason, committed. */
    private function defer(LessonFinancialDisposition $disposition, string $reason): LessonRefundExecutionResult
    {
        $disposition->forceFill([
            'processing_status' => LessonFinancialDispositionStatus::ManualReview,
            'admin_hold' => true,
            'reason_code' => $reason,
        ])->save();

        $this->audit->logSystem(
            self::LOG_NAME,
            'lesson_refund_deferred',
            sprintf('Lesson %s refund deferred to manual review (%s).', $disposition->lesson_id, $reason),
            $disposition,
            ['lesson_id' => $disposition->lesson_id, 'booking_id' => $disposition->booking_id, 'reason_code' => $reason],
        );

        return new LessonRefundExecutionResult($disposition, null, credited: false);
    }

    /** The cancellation flow already credited this charge — link it, never duplicate it. */
    private function settleAlreadyRefunded(LessonFinancialDisposition $disposition, WalletLedgerEntry $existing): LessonRefundExecutionResult
    {
        $disposition->forceFill([
            'refund_ledger_entry_id' => $existing->id,
            'processing_status' => LessonFinancialDispositionStatus::Resolved,
            'reason_code' => 'already_refunded_by_cancellation',
            'resolved_at' => now()->toImmutable()->utc(),
        ])->save();

        $this->audit->logSystem(
            self::LOG_NAME,
            'lesson_refund_already_completed',
            sprintf('Lesson %s refund already credited by the cancellation flow — linked, not duplicated.', $disposition->lesson_id),
            $disposition,
            ['lesson_id' => $disposition->lesson_id, 'wallet_ledger_entry_id' => $existing->id],
        );

        return new LessonRefundExecutionResult($disposition, $existing, credited: false);
    }

    private function auditExecution(LessonFinancialDisposition $disposition, WalletLedgerEntry $entry, ?User $admin): void
    {
        $properties = [
            'lesson_id' => $disposition->lesson_id,
            'booking_id' => $disposition->booking_id,
            'wallet_ledger_entry_id' => $entry->id,
            'amount_minor' => $entry->amount_minor,
            'currency' => $entry->currency_code,
            'balance_before_minor' => $entry->balance_after_minor - $entry->amount_minor,
            'balance_after_minor' => $entry->balance_after_minor,
            'reason_code' => 'refund_completed',
            'outcome' => $disposition->outcome->value,
        ];

        $admin !== null
            ? $this->audit->logUser($admin, self::LOG_NAME, 'lesson_refund_executed', sprintf('Lesson %s wallet refund executed.', $disposition->lesson_id), $disposition, $properties)
            : $this->audit->logSystem(self::LOG_NAME, 'lesson_refund_executed', sprintf('Lesson %s wallet refund executed.', $disposition->lesson_id), $disposition, $properties);
    }
}
