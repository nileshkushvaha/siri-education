<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentReconciliationIssueService;
use App\Services\AuditTrailService;
use App\Wallet\DTOs\WalletRechargeSettlementResult;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Events\WalletRechargeCreditFailed;
use App\Wallet\Events\WalletRechargeSucceeded;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Exceptions\WalletNotUsableException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place a wallet recharge becomes real money in a wallet.
 *
 * Both entry points — the verified webhook and the reconciliation sweep
 * — call `settle()` with the same VerifiedPaymentEvent, so there is
 * exactly one settlement code path rather than two that must be kept in
 * agreement. This is the wallet counterpart of
 * PackagePurchaseSettlementService and follows it deliberately closely.
 *
 * ## Separation of concerns
 *
 *     webhook controller  transport + authenticity
 *     PaymentService      attempt-record mechanics
 *     THIS SERVICE        recharge lifecycle
 *     WalletLedgerService the ONLY writer of a wallet balance
 *
 * This service never sees an HTTP request, a raw provider payload, a
 * signature, or a gateway client — only an event already proven
 * authentic. It also never mutates a balance itself: it decides that a
 * credit is owed and asks WalletLedgerService to post it.
 *
 * ## The invariant, and where it deliberately differs from packages
 *
 * On success these are written in ONE transaction and are therefore
 * always true together, or not at all:
 *
 *     Payment         -> Paid
 *     WalletRecharge  -> Succeeded (+ succeeded_at)
 *     WalletLedger    -> exactly one credit entry
 *
 * But unlike a package activation, a wallet credit has a legitimate,
 * PERSISTENT business failure: the destination wallet may be frozen or
 * closed. Rolling the whole thing back would leave the attempt looking
 * unpaid while the provider holds real money, and the provider would
 * retry forever against a wallet that will still be frozen.
 *
 * So a credit refusal is handled in a SECOND transaction: the Payment
 * stays Paid (it is true — the money was collected), and the recharge
 * is recorded as CreditFailed, which is durable, operator-visible and
 * retryable via retryCredit() without any further provider interaction.
 * A transient/unexpected failure still throws, so the caller can tell
 * the provider to retry.
 */
final class WalletRechargeSettlementService
{
    private const string LOG_NAME = 'wallet_recharges';

    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly WalletLedgerService $ledger,
        private readonly PaymentReconciliationIssueService $issues,
    ) {}

    /**
     * Applies a verified provider event to a wallet-recharge payment.
     *
     * Never throws for an ordinary "not actionable" outcome — those come
     * back as an ignored result so the caller can acknowledge and stop
     * the provider retrying. It DOES throw when settlement was supposed
     * to happen and could not for a transient reason, because that is
     * precisely when a retry is wanted.
     *
     * @throws WalletException when a legitimate settlement fails and must be retried
     */
    public function settle(Payment $payment, VerifiedPaymentEvent $event): WalletRechargeSettlementResult
    {
        if ($payment->payable_type !== WalletRecharge::PAYABLE_TYPE) {
            // A wallet settlement path must never touch a booking
            // payment, a package purchase, or a future payable.
            return WalletRechargeSettlementResult::ignored($payment, null, 'This payment does not belong to a wallet recharge.');
        }

        if (strtolower((string) $payment->provider) !== strtolower($event->provider)) {
            return WalletRechargeSettlementResult::ignored($payment, null, 'The event provider does not match the payment attempt.');
        }

        return match ($event->type) {
            PaymentEventType::Succeeded => $this->applySuccess($payment, $event),
            PaymentEventType::Failed => $this->applyFailure($payment, $event),
            PaymentEventType::Processing => $this->applyProcessing($payment),
            PaymentEventType::Ignored => WalletRechargeSettlementResult::ignored($payment, null, 'Event type is not actionable.'),
        };
    }

    /**
     * Re-attempts the wallet credit for a recharge whose payment is
     * already settled — the recovery path for CreditFailed/CreditPending.
     *
     * Deliberately makes NO provider call and creates NO new Payment:
     * the money is already collected, and the only thing that failed is
     * local. Idempotent by the ledger key, so a repeat call after a
     * success is a no-op.
     *
     * @throws WalletException when the recharge is not awaiting a credit
     */
    public function retryCredit(WalletRecharge $recharge): WalletRechargeSettlementResult
    {
        $payment = $recharge->payments()->where('status', PaymentStatus::Paid)->first();

        if ($payment === null) {
            throw new WalletException(sprintf('Recharge %s has no settled payment to credit against.', $recharge->reference));
        }

        if (! $recharge->status->needsCreditRetry()) {
            throw new WalletException(sprintf('Recharge %s is %s and does not need a credit retry.', $recharge->reference, $recharge->status->label()));
        }

        return $this->creditAndFinalize($payment, $recharge, Carbon::now());
    }

    /** @throws WalletException */
    private function applySuccess(Payment $payment, VerifiedPaymentEvent $event): WalletRechargeSettlementResult
    {
        $settledAt = Carbon::now();

        // ── Phase 1: agree that the money arrived ────────────────────
        // Validation and the Payment/recharge state move happen under a
        // lock. The wallet credit itself is phase 2, because its failure
        // mode must NOT roll this back.
        $prepared = DB::transaction(function () use ($payment, $event, $settledAt): WalletRechargeSettlementResult {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $recharge = WalletRecharge::query()
                ->whereKey($payment->payable_id)
                ->lockForUpdate()
                ->first();

            if ($recharge === null) {
                // Never invent a financial record from a webhook.
                return WalletRechargeSettlementResult::ignored($payment, null, 'The recharge this payment refers to no longer exists.');
            }

            $mismatch = $this->validateProviderReference($payment, $recharge, $event)
                ?? $this->validateAmountAndCurrency($payment, $recharge, $event);

            if ($mismatch !== null) {
                return $mismatch;
            }

            // ── Replay: already fully settled ────────────────────────
            if ($payment->status === PaymentStatus::Paid && $recharge->status === WalletRechargeStatus::Succeeded) {
                return WalletRechargeSettlementResult::replayed($payment, $recharge);
            }

            if ($payment->status->isTerminal() && $payment->status !== PaymentStatus::Paid) {
                // A Failed/Cancelled attempt is never resurrected — the
                // provider is talking about an attempt we already closed.
                return WalletRechargeSettlementResult::ignored($payment, $recharge, sprintf('The payment attempt is %s and cannot be settled.', $payment->status->label()));
            }

            if ($recharge->status->isTerminal() && $recharge->status !== WalletRechargeStatus::Succeeded) {
                return WalletRechargeSettlementResult::ignored($payment, $recharge, sprintf('Recharge %s is %s and cannot be settled.', $recharge->reference, $recharge->status->label()));
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

            // CreditPending records "the money is ours, the credit has
            // not been applied yet" — durable, so a crash between the
            // two phases leaves a recoverable state rather than a
            // silently uncredited capture.
            if ($recharge->status !== WalletRechargeStatus::CreditPending) {
                $recharge->fill(['status' => WalletRechargeStatus::CreditPending])->save();
            }

            return WalletRechargeSettlementResult::settled($payment->refresh(), $recharge->refresh());
        });

        // Mismatch, replay, or a refusal — nothing left to credit.
        if (! $prepared->settled) {
            return $prepared;
        }

        // ── Phase 2: the wallet credit ───────────────────────────────
        return $this->creditAndFinalize($prepared->payment, $prepared->recharge, $settledAt);
    }

    /**
     * Posts the ledger credit and converges the recharge to Succeeded.
     *
     * Shared by first-time settlement and by retryCredit(), so recovery
     * can never take a different path from the original attempt.
     */
    private function creditAndFinalize(Payment $payment, WalletRecharge $recharge, Carbon $settledAt): WalletRechargeSettlementResult
    {
        try {
            $result = DB::transaction(function () use ($payment, $recharge, $settledAt): WalletRechargeSettlementResult {
                $locked = WalletRecharge::query()->whereKey($recharge->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === WalletRechargeStatus::Succeeded) {
                    return WalletRechargeSettlementResult::replayed($payment, $locked);
                }

                $wallet = Wallet::query()->whereKey($locked->wallet_id)->lockForUpdate()->first();

                if (
                    $wallet === null
                    || (int) $wallet->user_id !== (int) $locked->user_id
                    || strtoupper($wallet->currency_code) !== strtoupper((string) $locked->currency_code)
                ) {
                    throw new WalletNotUsableException('The destination wallet is no longer valid for this recharge.');
                }

                // The single wallet-balance writer. Keyed on the
                // RECHARGE, not the payment or the event, so a retry
                // through any route resolves to the same entry and the
                // DB's unique(idempotency_key) is the final backstop
                // against a double credit.
                $this->ledger->credit(
                    $wallet,
                    (int) $locked->amount_minor,
                    WalletLedgerEntryType::RechargeConfirmed,
                    $locked->user,
                    idempotencyKey: $this->creditIdempotencyKey($locked),
                    description: sprintf('Wallet recharge %s.', $locked->reference),
                    sourceType: WalletRecharge::class,
                    sourceId: (string) $locked->id,
                );

                $locked->fill([
                    'status' => WalletRechargeStatus::Succeeded,
                    'succeeded_at' => $settledAt,
                    'failure_code' => null,
                    'failure_reason' => null,
                ])->save();

                return WalletRechargeSettlementResult::settled($payment, $locked->refresh());
            });
        } catch (WalletNotUsableException $e) {
            // A REAL, PERSISTENT refusal. The money stays collected and
            // the Payment stays Paid — pretending otherwise would be a
            // lie about money we hold. The recharge becomes durably
            // retryable instead.
            return $this->recordCreditFailure($payment, $recharge, 'wallet_not_usable', $e->getMessage());
        }

        if ($result->settled) {
            $this->audit->logSystem(
                self::LOG_NAME,
                'settled',
                sprintf('Wallet recharge %s settled and credited.', $recharge->reference),
                $result->recharge,
                [
                    'payment_id' => $payment->id,
                    'amount_minor' => (int) $recharge->amount_minor,
                    'currency_code' => $recharge->currency_code,
                ],
            );

            // A successful settlement proves any earlier discrepancy on
            // this attempt is over. After commit, so a rolled-back
            // settlement can never close an issue against money that
            // never landed.
            $this->issues->resolveOpenIssuesFor($payment);

            // The provider-agnostic seam: the student's confirmation and
            // receipt hang off this event, never off a Razorpay webhook.
            // Only the `settled` branch reaches here, so a replayed
            // delivery cannot produce a second notification — and it
            // fires only after the CREDIT succeeded, never merely
            // because the Payment was paid.
            WalletRechargeSucceeded::dispatch($result->recharge);
        }

        return $result;
    }

    private function recordCreditFailure(Payment $payment, WalletRecharge $recharge, string $code, string $reason): WalletRechargeSettlementResult
    {
        $failed = DB::transaction(function () use ($recharge, $code, $reason): WalletRecharge {
            $locked = WalletRecharge::query()->whereKey($recharge->id)->lockForUpdate()->firstOrFail();

            $locked->fill([
                'status' => WalletRechargeStatus::CreditFailed,
                'failure_code' => $code,
                'failure_reason' => $reason,
            ])->save();

            return $locked->refresh();
        });

        $this->audit->logSystem(
            self::LOG_NAME,
            'credit_failed',
            sprintf('Wallet recharge %s was captured by the provider but could not be credited.', $recharge->reference),
            $failed,
            ['failure_code' => $code, 'payment_id' => $payment->id, 'amount_minor' => (int) $recharge->amount_minor],
        );

        // Money collected that cannot reach a wallet needs an operator,
        // not another silent retry — recorded on the canonical issue
        // queue rather than a wallet-only error table.
        $this->issues->record(
            $payment,
            PaymentReconciliationIssueType::SettlementFailed,
            ['failure_code' => $code, 'recharge_reference' => $recharge->reference],
        );

        WalletRechargeCreditFailed::dispatch($failed);

        return WalletRechargeSettlementResult::creditFailed($payment, $failed, $reason);
    }

    private function applyFailure(Payment $payment, VerifiedPaymentEvent $event): WalletRechargeSettlementResult
    {
        return DB::transaction(function () use ($payment, $event): WalletRechargeSettlementResult {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $recharge = WalletRecharge::query()->whereKey($payment->payable_id)->lockForUpdate()->first();

            if ($recharge === null) {
                return WalletRechargeSettlementResult::ignored($payment, null, 'The recharge this payment refers to no longer exists.');
            }

            // A capture already confirmed is never downgraded to a
            // failure by a later `payment.failed` for the same attempt.
            // That money is real and belongs on the credit-retry path.
            if ($payment->status === PaymentStatus::Paid || $recharge->status->needsCreditRetry() || $recharge->status === WalletRechargeStatus::Succeeded) {
                return WalletRechargeSettlementResult::ignored($payment, $recharge, 'This payment is already captured and cannot be marked failed.');
            }

            if ($payment->status->isTerminal()) {
                return WalletRechargeSettlementResult::ignored($payment, $recharge, sprintf('The payment attempt is already %s.', $payment->status->label()));
            }

            $payment->fill([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'failure_message' => $event->reason,
            ])->save();

            // The RECHARGE is deliberately NOT marked failed. A student
            // may retry, which opens a NEW Payment attempt against this
            // same recharge — closing the recharge here would force a
            // duplicate domain record for a single intent to add money.
            $this->audit->logSystem(
                self::LOG_NAME,
                'payment_failed',
                sprintf('A payment attempt for wallet recharge %s failed; the wallet was not credited.', $recharge->reference),
                $recharge,
                ['payment_id' => $payment->id],
            );

            return WalletRechargeSettlementResult::ignored($payment->refresh(), $recharge, 'Payment failed; the wallet was not credited.');
        });
    }

    /** Authorized/processing is not captured — it never credits a wallet. */
    private function applyProcessing(Payment $payment): WalletRechargeSettlementResult
    {
        if ($payment->status->isTerminal()) {
            return WalletRechargeSettlementResult::ignored($payment, null, 'The payment attempt has already settled.');
        }

        if ($payment->status !== PaymentStatus::Processing) {
            $payment->fill(['status' => PaymentStatus::Processing])->save();
        }

        return WalletRechargeSettlementResult::ignored($payment->refresh(), null, 'Payment is in flight; no wallet credit yet.');
    }

    /**
     * An event that resolved by OUR reference must still be talking
     * about the same provider order we created.
     *
     * The attempt is normally found by `payment_reference` (our own key,
     * carried in Razorpay notes / Stripe metadata). That is the most
     * specific signal, but it is metadata — if an event arrives bearing
     * our reference while naming a DIFFERENT provider order, the two
     * facts disagree and settling would mean crediting a wallet for an
     * order we cannot account for.
     *
     * The pre-cutover wallet code enforced exactly this ("Recharge
     * provider reference does not match the confirmed event") and the
     * guarantee is deliberately carried across rather than dropped. It
     * lives here rather than in the shared settlement path because
     * changing settlement for booking and package payments is not this
     * phase's business.
     *
     * A null order id on either side is not a conflict: an event that
     * simply does not carry one proves nothing either way.
     */
    private function validateProviderReference(Payment $payment, WalletRecharge $recharge, VerifiedPaymentEvent $event): ?WalletRechargeSettlementResult
    {
        if ($payment->provider_order_id === null || $event->providerOrderId === null) {
            return null;
        }

        if ($payment->provider_order_id === $event->providerOrderId) {
            return null;
        }

        $this->issues->record(
            $payment,
            PaymentReconciliationIssueType::AmountMismatch,
            [
                'expected_provider_order_id' => $payment->provider_order_id,
                'observed_provider_order_id' => $event->providerOrderId,
                'expected_amount_minor' => (int) $recharge->amount_minor,
                'expected_currency' => strtoupper((string) $recharge->currency_code),
            ],
            $event->source,
        );

        return WalletRechargeSettlementResult::ignored($payment, $recharge, sprintf(
            'The event names provider order %s but the attempt is %s.',
            $event->providerOrderId,
            $payment->provider_order_id,
        ));
    }

    /**
     * A valid signature proves the message came from the provider — it
     * proves nothing about WHAT was collected. All three of provider,
     * attempt, and recharge must agree on the money before any wallet is
     * credited. There is no conversion, ever: a currency difference is a
     * discrepancy to investigate, never something to reconcile
     * arithmetically.
     */
    private function validateAmountAndCurrency(Payment $payment, WalletRecharge $recharge, VerifiedPaymentEvent $event): ?WalletRechargeSettlementResult
    {
        // A success event that does not say what was collected proves
        // nothing about the money, and every provider SIRI collects
        // through does report both figures. Treating the silence as
        // agreement would mean crediting a wallet on an unverified
        // amount, so wallet settlement fails closed here rather than
        // skipping the check.
        if ($event->amountMinor === null || $event->currencyCode === null) {
            $this->issues->record(
                $payment,
                PaymentReconciliationIssueType::AmountMismatch,
                [
                    'expected_amount_minor' => (int) $recharge->amount_minor,
                    'expected_currency' => strtoupper((string) $recharge->currency_code),
                    'observed_amount_minor' => $event->amountMinor,
                    'observed_currency' => $event->currencyCode,
                ],
                $event->source,
            );

            return WalletRechargeSettlementResult::ignored(
                $payment,
                $recharge,
                'The provider confirmed payment without reporting an amount and currency.',
            );
        }

        if ($event->amountMinor !== (int) $payment->amount_minor || $event->amountMinor !== (int) $recharge->amount_minor) {
            $this->issues->record(
                $payment,
                PaymentReconciliationIssueType::AmountMismatch,
                [
                    'expected_amount_minor' => (int) $recharge->amount_minor,
                    'observed_amount_minor' => $event->amountMinor,
                    'expected_currency' => strtoupper((string) $recharge->currency_code),
                ],
                $event->source,
            );

            return WalletRechargeSettlementResult::ignored($payment, $recharge, sprintf(
                'The provider reported %d but the attempt is %d and the recharge is %d.',
                $event->amountMinor,
                $payment->amount_minor,
                $recharge->amount_minor,
            ));
        }

        $eventCurrency = strtoupper($event->currencyCode);

        if ($eventCurrency !== strtoupper((string) $payment->currency_code) || $eventCurrency !== strtoupper((string) $recharge->currency_code)) {
            $this->issues->record(
                $payment,
                PaymentReconciliationIssueType::CurrencyMismatch,
                [
                    'expected_currency' => strtoupper((string) $recharge->currency_code),
                    'observed_currency' => $eventCurrency,
                    'expected_amount_minor' => (int) $recharge->amount_minor,
                ],
                $event->source,
            );

            return WalletRechargeSettlementResult::ignored($payment, $recharge, sprintf(
                'The provider reported %s but the attempt is %s and the recharge is %s.',
                $eventCurrency,
                $payment->currency_code,
                $recharge->currency_code,
            ));
        }

        return null;
    }

    /** Keyed on the recharge so every route — webhook, reconciliation, manual retry — resolves to the same ledger entry. */
    private function creditIdempotencyKey(WalletRecharge $recharge): string
    {
        return sprintf('wallet-recharge:%s', $recharge->id);
    }
}
