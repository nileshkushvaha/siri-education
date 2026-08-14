<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Package\DTOs\PackageSettlementResult;
use App\Package\Enums\PackagePurchaseStatus;
use App\Package\Exceptions\PackageException;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentReconciliationIssueService;
use App\Services\AuditTrailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4B.3 — the ONE place a package payment becomes real.
 *
 * Both entry points (the verified webhook and the reconciliation
 * sweep) call `settle()` with the same VerifiedPaymentEvent, so there
 * is exactly one settlement code path rather than two that must be
 * kept in agreement.
 *
 * ## The invariant
 *
 * These three facts are written in ONE transaction and are therefore
 * always true together, or not at all:
 *
 *     Payment      -> Paid
 *     Purchase     -> Paid (+ paid_at)
 *     Entitlement  -> exists, Active (+ activated_at, expires_at)
 *
 * If entitlement creation throws, the whole transaction rolls back and
 * the attempt stays Pending locally. The caller then reports failure so
 * the provider retries, and the reconciliation sweep is a second safety
 * net. There is deliberately NO intermediate "paid but not activated"
 * status: the wallet domain needs one because crediting a wallet can
 * legitimately fail for reasons that persist (a frozen or closed
 * wallet), whereas entitlement creation has no such business-level
 * failure — its only realistic failures are transient, which is exactly
 * what rollback-and-retry handles best. Adding a durable failure state
 * here would create a stuck state that nothing in the domain can
 * legitimately produce.
 *
 * ## Separation of concerns
 *
 *     webhook controller  transport + authenticity
 *     PaymentService      attempt-record mechanics
 *     THIS SERVICE        package commercial + entitlement lifecycle
 *
 * This service never sees an HTTP request, a raw provider payload, or
 * a signature — only an event that has already been proven authentic.
 */
final class PackagePurchaseSettlementService
{
    private const string LOG_NAME = 'student_package_purchases';

    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly PackageEntitlementService $entitlements,
        private readonly PaymentReconciliationIssueService $issues,
    ) {}

    /**
     * Applies a verified provider event to a package payment attempt.
     *
     * Never throws for an ordinary "not actionable" outcome — those
     * come back as an ignored result so the caller can acknowledge and
     * stop the provider retrying. It DOES throw when settlement was
     * supposed to happen and could not, because that is precisely the
     * case where a retry is wanted.
     *
     * @throws PackageException when a legitimate settlement fails and must be retried
     */
    public function settle(Payment $payment, VerifiedPaymentEvent $event): PackageSettlementResult
    {
        if ($payment->payable_type !== StudentPackagePurchase::PAYABLE_TYPE) {
            // A package settlement path must never touch a booking
            // payment, a wallet recharge, or a future payable.
            return $this->ignore($payment, null, 'not_a_package_payment', 'This payment does not belong to a package purchase.');
        }

        if (strtolower((string) $payment->provider) !== strtolower($event->provider)) {
            return $this->ignore($payment, null, 'provider_mismatch', 'The event provider does not match the payment attempt.');
        }

        return match ($event->type) {
            PaymentEventType::Succeeded => $this->applySuccess($payment, $event),
            PaymentEventType::Failed => $this->applyFailure($payment, $event),
            PaymentEventType::Processing => $this->applyProcessing($payment),
            PaymentEventType::Ignored => PackageSettlementResult::ignored($payment, null, 'Event type is not actionable.'),
        };
    }

    /** @throws PackageException */
    private function applySuccess(Payment $payment, VerifiedPaymentEvent $event): PackageSettlementResult
    {
        // ONE timestamp for paid_at, activated_at, and the expiry
        // calculation — otherwise the three drift by milliseconds and
        // "valid for exactly 90 days" quietly stops being exact.
        $settledAt = Carbon::now();

        $result = DB::transaction(function () use ($payment, $event, $settledAt): PackageSettlementResult {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $purchase = StudentPackagePurchase::query()
                ->whereKey($payment->payable_id)
                ->lockForUpdate()
                ->first();

            if ($purchase === null) {
                // Never invent commercial records from a webhook.
                return $this->ignore($payment, null, 'unknown_purchase', 'The purchase this payment refers to no longer exists.');
            }

            $mismatch = $this->validateAmountAndCurrency($payment, $purchase, $event);

            if ($mismatch !== null) {
                return $mismatch;
            }

            // ── Replay: already fully settled, nothing to do ──────────
            if ($payment->status === PaymentStatus::Paid && $purchase->status === PackagePurchaseStatus::Paid) {
                return PackageSettlementResult::replayed($payment, $purchase, $this->existingEntitlement($purchase));
            }

            if ($payment->status->isTerminal() && $payment->status !== PaymentStatus::Paid) {
                // A Failed/Cancelled attempt is never resurrected — the
                // provider is talking about an attempt we already closed.
                return $this->ignore($payment, $purchase, 'attempt_already_closed', sprintf('The payment attempt is %s and cannot be settled.', $payment->status->label()));
            }

            if ($payment->status !== PaymentStatus::Paid) {
                $payment->fill([
                    'status' => PaymentStatus::Paid,
                    // Never overwrite an id the provider already gave us.
                    'provider_payment_id' => $payment->provider_payment_id ?? $event->providerPaymentId,
                    'paid_at' => $settledAt,
                    'last_synced_at' => $settledAt,
                ])->save();
            }

            if ($purchase->status !== PackagePurchaseStatus::Paid) {
                if (! $purchase->status->canTransitionTo(PackagePurchaseStatus::Paid)) {
                    throw new PackageException(sprintf('A purchase cannot move from "%s" to "paid".', $purchase->status->value));
                }

                $purchase->fill([
                    'status' => PackagePurchaseStatus::Paid,
                    'paid_at' => $settledAt,
                ])->save();
            }

            // Exactly once, guaranteed twice over: the row lock above
            // serialises concurrent settlements, and UNIQUE(proposal_id)
            // is the database's own final word.
            $entitlement = $this->existingEntitlement($purchase) ?? $this->activate($purchase, $settledAt);

            return PackageSettlementResult::settled($payment->refresh(), $purchase->refresh(), $entitlement);
        });

        if ($result->settled) {
            $this->auditSettlement($result, $settledAt);

            // Phase 4E.2 — a successful settlement proves any earlier
            // discrepancy on this attempt is over (transient, or
            // corrected upstream). Closing it automatically is what
            // keeps the queue worth reading: an operator must only ever
            // see problems that are still real. Deliberately AFTER the
            // transaction commits, so a rolled-back settlement can never
            // leave an issue closed against money that never landed.
            $this->issues->resolveOpenIssuesFor($result->payment ?? $payment);
        }

        return $result;
    }

    /**
     * Creates the lesson balance and starts the validity clock.
     *
     * @throws PackageException when the purchase has lost the proposal it was bought from
     */
    private function activate(StudentPackagePurchase $purchase, Carbon $settledAt): StudentPackageEntitlement
    {
        $proposal = $purchase->proposal;

        if ($proposal === null) {
            throw new PackageException('This purchase has no proposal to activate lessons from.');
        }

        $entitlement = $this->entitlements->createFromProposal($proposal);

        // The validity clock starts NOW — when the paid lessons become
        // usable — not at proposal creation, approval, acceptance, or
        // attempt creation. `validity_days` is the snapshot taken when
        // the proposal was created, so a later admin edit to the offer
        // cannot change what this student already bought.
        $validityDays = $proposal->validity_days;

        $entitlement->forceFill([
            'activated_at' => $settledAt,
            'expires_at' => $validityDays === null ? null : $settledAt->copy()->addDays($validityDays),
        ])->save();

        return $entitlement->refresh();
    }

    private function existingEntitlement(StudentPackagePurchase $purchase): ?StudentPackageEntitlement
    {
        return StudentPackageEntitlement::query()->where('proposal_id', $purchase->proposal_id)->first();
    }

    /**
     * A valid signature proves the message came from the provider — it
     * proves nothing about WHAT was collected. All three of provider,
     * attempt, and purchase must agree on the money before any lesson
     * is granted.
     */
    private function validateAmountAndCurrency(Payment $payment, StudentPackagePurchase $purchase, VerifiedPaymentEvent $event): ?PackageSettlementResult
    {
        if ($event->amountMinor !== null && ($event->amountMinor !== (int) $payment->amount_minor || $event->amountMinor !== (int) $purchase->amount_minor)) {
            // Phase 4E.2 (PKG-AUD-014) — this refusal used to leave only
            // an activity-log line while the provider potentially held
            // real money. It now also raises a durable operator-visible
            // issue. Recorded HERE, in the one validator both the
            // webhook and the reconciliation sweep pass through, so
            // there is exactly one discrepancy detector rather than two
            // that must agree.
            $this->issues->record(
                $payment,
                PaymentReconciliationIssueType::AmountMismatch,
                [
                    'expected_amount_minor' => (int) $purchase->amount_minor,
                    'observed_amount_minor' => $event->amountMinor,
                    'expected_currency' => strtoupper((string) $purchase->currency_code),
                ],
                $event->source,
            );

            return $this->ignore($payment, $purchase, 'amount_mismatch', sprintf(
                'The provider reported %d but the attempt is %d and the purchase is %d.',
                $event->amountMinor,
                $payment->amount_minor,
                $purchase->amount_minor,
            ));
        }

        // No conversion, ever — a currency difference is a discrepancy
        // to be investigated, never something to reconcile arithmetically.
        if ($event->currencyCode !== null) {
            $eventCurrency = strtoupper($event->currencyCode);

            if ($eventCurrency !== strtoupper((string) $payment->currency_code) || $eventCurrency !== strtoupper((string) $purchase->currency_code)) {
                $this->issues->record(
                    $payment,
                    PaymentReconciliationIssueType::CurrencyMismatch,
                    [
                        'expected_currency' => strtoupper((string) $purchase->currency_code),
                        'observed_currency' => $eventCurrency,
                        'expected_amount_minor' => (int) $purchase->amount_minor,
                    ],
                    $event->source,
                );

                return $this->ignore($payment, $purchase, 'currency_mismatch', sprintf(
                    'The provider reported %s but the attempt is %s and the purchase is %s.',
                    $eventCurrency,
                    $payment->currency_code,
                    $purchase->currency_code,
                ));
            }
        }

        return null;
    }

    /** A genuine provider failure closes the attempt — and only the attempt. */
    private function applyFailure(Payment $payment, VerifiedPaymentEvent $event): PackageSettlementResult
    {
        $result = DB::transaction(function () use ($payment, $event): PackageSettlementResult {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $purchase = StudentPackagePurchase::query()->whereKey($payment->payable_id)->first();

            // An out-of-order "failed" arriving after a confirmed
            // capture must never reverse collected money.
            if ($payment->status === PaymentStatus::Paid) {
                return $this->ignore($payment, $purchase, 'already_paid', 'A settled payment cannot be failed.');
            }

            if ($payment->status->isTerminal()) {
                return PackageSettlementResult::ignored($payment, $purchase, sprintf('The payment attempt is already %s.', $payment->status->label()));
            }

            $payment->fill([
                'status' => PaymentStatus::Failed,
                'failure_code' => 'provider_reported_failure',
                'failure_message' => $event->reason,
                'failed_at' => now(),
                'last_synced_at' => now(),
            ])->save();

            // The purchase deliberately stays PendingPayment: one
            // declined card does not kill the accepted package.
            return PackageSettlementResult::ignored($payment->refresh(), $purchase, 'Payment attempt failed.');
        });

        $this->audit->logSystem(
            self::LOG_NAME,
            'package_payment_failed',
            sprintf('Package payment attempt failed (%s).', $event->provider),
            $result->purchase ?? $payment,
            ['payment_id' => $payment->id, 'provider' => $event->provider],
        );

        return $result;
    }

    /** In-flight is genuine information, but it settles nothing. */
    private function applyProcessing(Payment $payment): PackageSettlementResult
    {
        if ($payment->status === PaymentStatus::Pending) {
            $payment->fill(['status' => PaymentStatus::Processing, 'last_synced_at' => now()])->save();
        }

        return PackageSettlementResult::ignored($payment->refresh(), null, 'Payment is still processing.');
    }

    private function auditSettlement(PackageSettlementResult $result, Carbon $settledAt): void
    {
        $this->audit->logSystem(
            self::LOG_NAME,
            'package_payment_settled',
            sprintf('Package purchase %s settled and activated.', $result->purchase?->reference),
            $result->purchase,
            [
                'payment_id' => $result->payment?->id,
                'purchase_reference' => $result->purchase?->reference,
                'amount_minor' => $result->purchase?->amount_minor,
                'currency_code' => $result->purchase?->currency_code,
                'entitlement_id' => $result->entitlement?->id,
                'total_quantity' => $result->entitlement?->total_quantity,
                'activated_at' => $settledAt->toIso8601String(),
                'expires_at' => $result->entitlement?->expires_at?->toIso8601String(),
            ],
        );
    }

    /** Audits a refusal and returns it — discrepancies must never be silent. */
    private function ignore(?Payment $payment, ?StudentPackagePurchase $purchase, string $code, string $reason): PackageSettlementResult
    {
        $this->audit->logSystem(
            self::LOG_NAME,
            'package_settlement_'.$code,
            $reason,
            $purchase ?? $payment,
            ['payment_id' => $payment?->id, 'code' => $code],
        );

        return PackageSettlementResult::ignored($payment, $purchase, $reason);
    }
}
