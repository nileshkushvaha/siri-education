<?php

declare(strict_types=1);

namespace App\Payments\Services;

use App\Models\Payment;
use App\Models\PaymentReconciliationIssue;
use App\Models\User;
use App\Payments\Enums\PaymentReconciliationIssueStatus;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Services\AuditTrailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4E.2 — the sole writer of PaymentReconciliationIssue.
 *
 * ONE detector, TWO discovery paths. The webhook and the scheduled
 * reconciliation sweep both reach settlement through
 * PackagePurchaseSettlementService::validateAmountAndCurrency(), and
 * that single validator calls this service. There is deliberately no
 * second discrepancy path to keep in agreement — the `source` metadata
 * key records which route noticed it, and nothing else differs.
 *
 * ## Why record() is idempotent by database, not by convention
 *
 * A provider that redelivers a mismatching webhook does so
 * concurrently and repeatedly. A read-then-insert would race and
 * produce duplicate operator rows for one problem, which is precisely
 * the failure mode that makes an alert queue useless. The unique index
 * on (payment_id, issue_type, open_issue_marker) is therefore the
 * guarantee, and the UniqueConstraintViolationException catch below is
 * the race loser folding into the winner's row rather than failing.
 *
 * ## What this service must never do
 *
 * Move money, or permit anyone else to. It writes only its own table.
 * Settlement remains reachable exclusively through verified provider
 * evidence, and resolving an issue — automatically or by an operator —
 * changes no Payment, no purchase, and no entitlement.
 */
final class PaymentReconciliationIssueService
{
    private const string LOG_NAME = 'payment_reconciliation_issues';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * Records that a discrepancy was observed — opening an issue the
     * first time, and counting the recurrence every time after.
     *
     * @param  array<string, mixed>  $evidence  normalized, non-sensitive only
     */
    public function record(
        Payment $payment,
        PaymentReconciliationIssueType $type,
        array $evidence = [],
        string $source = 'webhook',
    ): PaymentReconciliationIssue {
        $existing = $this->openIssueFor($payment, $type);

        if ($existing !== null) {
            return $this->recordRecurrence($existing, $source);
        }

        $now = now();

        try {
            $issue = PaymentReconciliationIssue::query()->create([
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'issue_type' => $type,
                'status' => PaymentReconciliationIssueStatus::Open,
                'expected_amount_minor' => $evidence['expected_amount_minor'] ?? null,
                'observed_amount_minor' => $evidence['observed_amount_minor'] ?? null,
                'expected_currency' => $evidence['expected_currency'] ?? null,
                'observed_currency' => $evidence['observed_currency'] ?? null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'occurrence_count' => 1,
                'metadata' => $this->safeMetadata($payment, $source),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent redelivery won the insert between our lookup
            // and this write. That IS the deduplication working — fold
            // into the existing row rather than surfacing a failure.
            $winner = $this->openIssueFor($payment, $type);

            return $winner !== null
                ? $this->recordRecurrence($winner, $source)
                : throw new \RuntimeException('A payment reconciliation issue could not be recorded.');
        }

        $this->audit->logSystem(
            self::LOG_NAME,
            'payment_reconciliation_issue_opened',
            sprintf('%s detected on a %s payment attempt; settlement refused.', $type->label(), $payment->provider),
            $issue,
            $this->auditContext($issue) + ['source' => $source],
        );

        return $issue;
    }

    /**
     * A later verified settlement proves the discrepancy is over —
     * either it was transient, or it was corrected upstream. Closing it
     * automatically is what keeps the queue trustworthy: an operator
     * must only ever see problems that are still real.
     *
     * @return int how many issues were closed
     */
    public function resolveOpenIssuesFor(Payment $payment, string $reason = 'Settled successfully by a later verified provider event.'): int
    {
        $open = PaymentReconciliationIssue::query()
            ->forPayment((string) $payment->id)
            ->open()
            ->get();

        foreach ($open as $issue) {
            $issue->fill([
                'status' => PaymentReconciliationIssueStatus::Resolved,
                'resolved_at' => now(),
                'resolution_note' => $reason,
            ])->save();

            $this->audit->logSystem(
                self::LOG_NAME,
                'payment_reconciliation_issue_auto_resolved',
                sprintf('%s resolved automatically after a successful settlement.', $issue->issue_type->label()),
                $issue,
                $this->auditContext($issue),
            );
        }

        return $open->count();
    }

    /**
     * An operator records that a discrepancy was handled out-of-band.
     *
     * This is bookkeeping ONLY. It deliberately takes no amount, no
     * status, and no provider reference, because there is nothing here
     * that could move money even if it wanted to — the absence of a
     * "mark paid" path is the point (spec Part 16/18).
     */
    public function resolveManually(PaymentReconciliationIssue $issue, User $actor, string $note): PaymentReconciliationIssue
    {
        return DB::transaction(function () use ($issue, $actor, $note): PaymentReconciliationIssue {
            $issue = PaymentReconciliationIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();

            if (! $issue->isOpen()) {
                return $issue;
            }

            $issue->fill([
                'status' => PaymentReconciliationIssueStatus::Resolved,
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
                'resolution_note' => $note,
            ])->save();

            $this->audit->logUser(
                $actor,
                self::LOG_NAME,
                'payment_reconciliation_issue_resolved',
                sprintf('%s marked resolved by an operator.', $issue->issue_type->label()),
                $issue,
                $this->auditContext($issue) + ['note' => $note],
            );

            return $issue->refresh();
        });
    }

    public function openIssueFor(Payment $payment, PaymentReconciliationIssueType $type): ?PaymentReconciliationIssue
    {
        return PaymentReconciliationIssue::query()
            ->forPayment((string) $payment->id)
            ->open()
            ->where('issue_type', $type)
            ->first();
    }

    /**
     * Counts a repeat sighting without inflating the queue. Row-locked
     * so two simultaneous redeliveries cannot both read the same count
     * and write the same incremented value.
     */
    private function recordRecurrence(PaymentReconciliationIssue $issue, string $source): PaymentReconciliationIssue
    {
        return DB::transaction(function () use ($issue, $source): PaymentReconciliationIssue {
            $locked = PaymentReconciliationIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();

            $locked->fill([
                'last_seen_at' => now(),
                'occurrence_count' => $locked->occurrence_count + 1,
                'metadata' => [...($locked->metadata ?? []), 'last_source' => $source],
            ])->save();

            $this->audit->logSystem(
                self::LOG_NAME,
                'payment_reconciliation_issue_recurred',
                sprintf('%s observed again (%d occurrences).', $locked->issue_type->label(), $locked->occurrence_count),
                $locked,
                $this->auditContext($locked) + ['source' => $source],
            );

            return $locked->refresh();
        });
    }

    /**
     * Normalized, non-sensitive context only.
     *
     * Never a raw webhook body, a signature, a credential, or card/UPI
     * data — an operator queue is exactly the wrong place for any of
     * those, and this table is deliberately readable by managers.
     *
     * @return array<string, mixed>
     */
    private function safeMetadata(Payment $payment, string $source): array
    {
        return array_filter([
            'source' => $source,
            'payable_type' => $payment->payable_type,
            'payable_id' => $payment->payable_id,
            'provider_order_id' => $payment->provider_order_id,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function auditContext(PaymentReconciliationIssue $issue): array
    {
        return [
            'issue_id' => $issue->id,
            'payment_id' => $issue->payment_id,
            'issue_type' => $issue->issue_type->value,
            'expected_amount_minor' => $issue->expected_amount_minor,
            'observed_amount_minor' => $issue->observed_amount_minor,
            'expected_currency' => $issue->expected_currency,
            'observed_currency' => $issue->observed_currency,
            'occurrence_count' => $issue->occurrence_count,
        ];
    }
}
