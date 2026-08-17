<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Models\Payment;
use App\Models\WalletRecharge;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Services\PaymentAttemptVerifier;
use App\Payments\Services\PaymentReconciliationIssueService;
use App\Payments\Services\PaymentService;
use App\Services\AuditTrailService;
use App\Wallet\DTOs\WalletRechargeSettlementResult;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Exceptions\WalletException;
use Throwable;

/**
 * The safety net behind the wallet recharge webhook.
 *
 * This class OWNS NO PROVIDER INTEGRATION. It used to hold its own
 * Razorpay and Stripe polling, its own gateway credentials, and its own
 * amount/currency comparison against `wallet_recharges` — a second
 * provider reconciliation engine racing the generic one for the same
 * facts. It now asks PaymentAttemptVerifier, the single canonical
 * "does the provider say this was paid?" implementation shared with
 * booking and package reconciliation, and hands the answer to the same
 * WalletRechargeSettlementService the webhook uses.
 *
 * It survives the cutover as a thin DOMAIN orchestrator because it does
 * two things the generic payment sweep genuinely cannot:
 *
 *   1. It scopes the sweep to wallet-recharge payables, so the wallet's
 *      recovery cadence and audit trail stay its own.
 *
 *   2. It retries CREDITS. A recharge in CreditFailed has a Paid
 *      Payment — the generic sweep is correct to consider it finished,
 *      because externally it is. What is unfinished is purely local: the
 *      ledger credit. No provider call can help, and no other component
 *      would ever look for it.
 */
final class WalletRechargeReconciliationService
{
    /**
     * How long an open attempt waits before the first provider poll.
     * Public: also the operational-monitoring staleness threshold read
     * by WalletFinancialReportRepository — one number, reused.
     */
    public const int DUE_AFTER_MINUTES = 10;

    /** How long an attempt may stay unresolved before an operator should see it. */
    public const int OPERATOR_VISIBLE_AFTER_MINUTES = self::DUE_AFTER_MINUTES * 6;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly WalletRechargeSettlementService $settlement,
        private readonly PaymentAttemptVerifier $verifier,
        private readonly PaymentReconciliationIssueService $issues,
        private readonly AuditTrailService $audit,
    ) {}

    /** @return int how many attempts and stalled credits were examined */
    public function reconcileDue(int $limit = 200): int
    {
        return $this->reconcileDuePayments($limit) + $this->retryStalledCredits($limit);
    }

    /** Provider-side recovery: attempts the webhook never resolved. */
    private function reconcileDuePayments(int $limit): int
    {
        $due = Payment::query()
            ->where('payable_type', WalletRecharge::PAYABLE_TYPE)
            ->reconciliationDue(now()->subMinutes(self::DUE_AFTER_MINUTES))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $payment) {
            $this->reconcileOne($payment);
        }

        return $due->count();
    }

    /**
     * Local-only recovery: money already collected whose ledger credit
     * has not been applied. Deliberately makes no provider call — the
     * external side of these is finished and correct.
     */
    private function retryStalledCredits(int $limit): int
    {
        $stalled = WalletRecharge::query()
            ->awaitingCredit()
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($stalled as $recharge) {
            try {
                $result = $this->settlement->retryCredit($recharge);
            } catch (WalletException) {
                // No settled payment yet, or no longer awaiting a credit
                // — the provider-side sweep above owns that case.
                continue;
            }

            if ($result->settled) {
                $this->audit->logSystem(
                    'wallet_recharges',
                    'reconciliation_credit_recovered',
                    sprintf('Reconciliation applied the pending wallet credit for recharge %s.', $recharge->reference),
                    $result->recharge,
                    ['amount_minor' => (int) $recharge->amount_minor, 'currency_code' => $recharge->currency_code],
                );
            }
        }

        return $stalled->count();
    }

    /**
     * Polls one attempt and settles it if the provider confirms payment.
     * Safe to call repeatedly: settlement is idempotent, so an attempt
     * already settled comes back as a replay and credits nothing twice.
     */
    public function reconcileOne(Payment $payment): WalletRechargeSettlementResult
    {
        if ($payment->payable_type !== WalletRecharge::PAYABLE_TYPE) {
            return WalletRechargeSettlementResult::ignored($payment, null, 'Not a wallet recharge payment.');
        }

        if (! $payment->status->isOpen() || $payment->provider_order_id === null) {
            // An OPEN attempt with no provider reference is not "nothing
            // to reconcile" — it is a checkout that started and never
            // reached the gateway. Nothing can poll it; it needs a human.
            if ($payment->status->isOpen()) {
                $this->detectStuckAttempt($payment);
            }

            $this->payments->markSynced($payment);

            return WalletRechargeSettlementResult::ignored($payment, null, 'Nothing to reconcile.');
        }

        $reachable = true;
        $confirmed = $this->providerConfirmsPayment($payment, $reachable);

        if ($confirmed === null) {
            // "The provider says not yet" and "we could not reach the
            // provider" are different facts, and only one needs a human.
            if (! $reachable) {
                $this->recordOperationalIssue($payment, PaymentReconciliationIssueType::ProviderUnavailable);
            } else {
                $this->detectStuckAttempt($payment);
            }

            $this->payments->markSynced($payment);

            return WalletRechargeSettlementResult::ignored($payment, null, 'The provider has not confirmed this payment.');
        }

        try {
            $result = $this->settlement->settle($payment, $confirmed);
        } catch (Throwable $e) {
            // Left open on purpose: the next sweep retries. The provider
            // has confirmed money and the student has nothing, so this
            // becomes an operator incident immediately — there is
            // nothing transient about it.
            $this->payments->markSynced($payment);

            $this->issues->record(
                $payment,
                PaymentReconciliationIssueType::SettlementFailed,
                [
                    'expected_amount_minor' => (int) $payment->amount_minor,
                    'expected_currency' => (string) $payment->currency_code,
                ],
                source: 'reconciliation',
            );

            $this->audit->logSystem(
                'wallet_recharges',
                'reconciliation_settlement_failed',
                sprintf('Reconciliation could not settle a provider-confirmed wallet recharge: %s', $e->getMessage()),
                $payment,
                ['payment_id' => $payment->id, 'provider' => $payment->provider],
            );

            return WalletRechargeSettlementResult::ignored($payment, null, $e->getMessage());
        }

        if ($result->settled) {
            $this->issues->resolveOpenIssuesFor($payment);

            $this->audit->logSystem(
                'wallet_recharges',
                'reconciliation_recovered',
                sprintf('Reconciliation recovered and credited wallet recharge %s.', $result->recharge?->reference),
                $result->recharge,
                ['payment_id' => $payment->id],
            );
        }

        $this->payments->markSynced($payment->refresh());

        return $result;
    }

    /**
     * Delegated to the canonical verifier so wallet, booking and package
     * reconciliation cannot drift about what "the provider says paid"
     * means. The rules it enforces — unreachable is never unpaid, and
     * the amount/currency compared are the provider's own reported
     * values, never our copy of them — are documented there.
     */
    private function providerConfirmsPayment(Payment $payment, bool &$reachable): ?VerifiedPaymentEvent
    {
        return $this->verifier->confirmedPayment($payment, $reachable);
    }

    /** An attempt the provider is reachable about but that still will not resolve. */
    private function detectStuckAttempt(Payment $payment): void
    {
        $threshold = now()->subMinutes(self::OPERATOR_VISIBLE_AFTER_MINUTES);

        if ($payment->provider_order_id === null) {
            $claimedAt = $payment->initialization_claimed_at;

            if ($claimedAt !== null && $claimedAt->lt($threshold)) {
                $this->recordOperationalIssue($payment, PaymentReconciliationIssueType::MissingProviderReference);
            }

            return;
        }

        if ($payment->created_at !== null && $payment->created_at->lt($threshold)) {
            $this->recordOperationalIssue($payment, PaymentReconciliationIssueType::StaleProcessing);
        }
    }

    /** Deduplicated by the generic issue service: one open issue per (payment, type). */
    private function recordOperationalIssue(Payment $payment, PaymentReconciliationIssueType $type): void
    {
        if ($payment->created_at !== null && $payment->created_at->gt(now()->subMinutes(self::OPERATOR_VISIBLE_AFTER_MINUTES))) {
            return;
        }

        $this->issues->record(
            $payment,
            $type,
            [
                'expected_amount_minor' => (int) $payment->amount_minor,
                'expected_currency' => (string) $payment->currency_code,
            ],
            source: 'reconciliation',
        );
    }

    /** Statuses this sweep can still act on — used by operational reporting. */
    public static function recoverableStatuses(): array
    {
        return [WalletRechargeStatus::Requested, WalletRechargeStatus::CreditPending, WalletRechargeStatus::CreditFailed];
    }
}
