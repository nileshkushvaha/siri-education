<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingPaymentReconciliationServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentReconciliationSeverity;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Exceptions\BookingException;
use App\Models\BookingPayment;
use App\Models\Payment;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentService;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The ONE place a generic payment attempt becomes a settled Booking.
 *
 * The mirror of PackagePurchaseSettlementService: the generic kernel
 * proves the money is real, and this bridges that proof into the
 * Booking domain. Both the verified webhook and the reconciliation
 * sweep call `settle()` with the same VerifiedPaymentEvent, so there is
 * exactly one settlement code path rather than two that must be kept in
 * agreement.
 *
 * ## What this deliberately does NOT do
 *
 * It reimplements no financial policy. Booking status rules, reservation
 * clearing, auto-confirmation, invoicing, the late-terminal wallet
 * recovery path — all of it already lives in
 * BookingPaymentService::markPaid(), which owns its own row lock and
 * idempotency. This service verifies, then delegates. Duplicating any of
 * that here would create a second definition of "a booking got paid".
 *
 * ## Separation of concerns
 *
 *     webhook controller   transport + authenticity
 *     PaymentService       attempt-record mechanics
 *     THIS SERVICE         attempt -> Booking obligation bridge
 *     BookingPaymentService  Booking commercial lifecycle
 *
 * It never sees an HTTP request, a raw provider payload, or a
 * signature — only an event already proven authentic.
 */
final class BookingPaymentSettlementService
{
    private const string LOG_NAME = 'payments';

    public function __construct(
        private readonly PaymentService $payments,
        private readonly BookingPaymentServiceInterface $bookings,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * Applies a verified provider event to a Booking payment attempt.
     *
     * Returns false for anything not actionable (replay, wrong payable,
     * non-success event) so the caller can acknowledge and stop the
     * provider retrying. Throws only when settlement was supposed to
     * happen and could not — precisely the case where a retry is wanted.
     *
     * @throws BookingException when a legitimate settlement fails and must be retried
     */
    public function settle(Payment $payment, VerifiedPaymentEvent $event): bool
    {
        if ($payment->payable_type !== BookingPayment::PAYABLE_TYPE) {
            return false;
        }

        $obligation = $payment->payable;

        if (! $obligation instanceof BookingPayment) {
            return false;
        }

        if ($event->type === PaymentEventType::Failed) {
            // Recording a failure IS processing the event — the attempt
            // becomes terminally Failed. The obligation deliberately
            // stays retryable: one declined card does not mean the
            // student no longer owes the money.
            return $this->recordFailure($payment, $event);
        }

        if ($event->type !== PaymentEventType::Succeeded) {
            return false;
        }

        // Already settled — a replayed webhook, or the sweep arriving
        // after the webhook won. Acknowledge without touching anything.
        if ($payment->status === PaymentStatus::Paid) {
            return false;
        }

        if (! $this->moneyMatches($payment, $event)) {
            // Deterministic refusal, not a transient failure. The
            // provider reporting a different amount will report the same
            // different amount on every retry, so this is acknowledged
            // and handed to an operator rather than left to loop.
            // Settlement simply does not happen.
            $this->refuseMismatch($payment, $obligation, $event);

            return false;
        }

        $booking = $obligation->booking;

        if ($booking === null) {
            throw new BookingException('The booking for this payment obligation could not be resolved.');
        }

        // The attempt is marked Paid first, inside its own transaction,
        // so the ledger is truthful about provider money even if the
        // Booking-side settlement then fails. That failure becomes an
        // operator incident rather than a lost payment.
        $this->payments->transition($payment, PaymentStatus::Paid, [
            'provider_payment_id' => $event->providerPaymentId,
        ]);

        try {
            DB::transaction(function () use ($obligation, $booking): void {
                $this->bookings->markPaid($booking, (string) $booking->payment_reference);

                BookingPayment::query()->whereKey($obligation->getKey())->update([
                    'status' => BookingPaymentRecordStatus::Captured->value,
                    'paid_at' => now(),
                ]);
            });
        } catch (BookingException $e) {
            // markPaid() refuses an already-settled booking by design —
            // that is a race we won on the attempt but lost on the
            // booking, not a failure. Settle the obligation and move on.
            $this->markObligationCaptured($obligation);

            return false;
        } catch (Throwable $e) {
            $this->raiseSettlementIncomplete($payment, $obligation, $e);

            throw new BookingException('The payment was collected but the booking could not be settled.');
        }

        return true;
    }

    private function markObligationCaptured(BookingPayment $obligation): void
    {
        BookingPayment::query()
            ->whereKey($obligation->getKey())
            ->whereNot('status', BookingPaymentRecordStatus::Captured->value)
            ->update([
                'status' => BookingPaymentRecordStatus::Captured->value,
                'paid_at' => now(),
            ]);
    }

    /** Verified provider money must equal the obligation exactly — never "close enough". */
    private function moneyMatches(Payment $payment, VerifiedPaymentEvent $event): bool
    {
        if ($event->amountMinor === null || $event->currencyCode === null) {
            return true;
        }

        return $event->amountMinor === (int) $payment->amount_minor
            && strtoupper((string) $event->currencyCode) === strtoupper((string) $payment->currency_code);
    }

    private function refuseMismatch(Payment $payment, BookingPayment $obligation, VerifiedPaymentEvent $event): void
    {
        $type = strtoupper((string) $event->currencyCode) !== strtoupper((string) $payment->currency_code)
            ? BookingPaymentReconciliationIssueType::CurrencyMismatch
            : BookingPaymentReconciliationIssueType::AmountMismatch;

        $this->raise($obligation, $type, BookingPaymentReconciliationSeverity::Warning, sprintf(
            'Provider reported %s %s for an obligation of %s %s.',
            (string) $event->amountMinor,
            (string) $event->currencyCode,
            (string) $payment->amount_minor,
            (string) $payment->currency_code,
        ));
    }

    private function raiseSettlementIncomplete(Payment $payment, BookingPayment $obligation, Throwable $e): void
    {
        $this->raise(
            $obligation,
            BookingPaymentReconciliationIssueType::ProviderSuccessLocalIncomplete,
            BookingPaymentReconciliationSeverity::Critical,
            sprintf('Provider confirmed payment %s but booking settlement failed: %s', $payment->id, $e->getMessage()),
        );
    }

    private function recordFailure(Payment $payment, VerifiedPaymentEvent $event): bool
    {
        if ($payment->status->isTerminal()) {
            return false;
        }

        $this->payments->transition($payment, PaymentStatus::Failed, [
            'failure_code' => 'provider_reported_failure',
            'failure_message' => $event->reason,
        ]);

        return true;
    }

    /**
     * Incident creation must never mask the financial fact that caused
     * it, so a failure to record one is reported and swallowed.
     */
    private function raise(
        BookingPayment $obligation,
        BookingPaymentReconciliationIssueType $type,
        BookingPaymentReconciliationSeverity $severity,
        string $detail,
    ): void {
        try {
            app(BookingPaymentReconciliationServiceInterface::class)
                ->raiseIssue($obligation, $type, $severity, $detail);
        } catch (Throwable $e) {
            report($e);

            $this->audit->logSystem(
                self::LOG_NAME,
                'booking_settlement_issue_unrecorded',
                sprintf('Could not record a booking payment incident: %s', $e->getMessage()),
                $obligation,
                ['type' => $type->value, 'detail' => $detail],
            );
        }
    }
}
