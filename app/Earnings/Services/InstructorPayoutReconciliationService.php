<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorPayoutProviderResolverInterface;
use App\Earnings\Contracts\InstructorPayoutReconciliationServiceInterface;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutReconciliationIssueStatus;
use App\Earnings\Enums\PayoutReconciliationIssueType;
use App\Earnings\Enums\PayoutReconciliationSeverity;
use App\Earnings\Events\InstructorPayoutReconciliationIssueDetected;
use App\Earnings\Events\InstructorPayoutReconciliationResolved;
use App\Earnings\Exceptions\PayoutExecutionException;
use App\Earnings\Exceptions\PayoutProviderException;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorPayoutReconciliationIssue;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the reconciliation sweep and the reconciliation-issue queue.
 * Deliberately reuses InstructorPayoutExecutionService::applyProviderStatus()
 * for every state transition it makes — reconciliation never applies a
 * financial effect through a separate code path than the job/event
 * handlers do, so success/failure/reversal finalization can never
 * diverge between "normal" and "reconciled" outcomes.
 */
final class InstructorPayoutReconciliationService implements InstructorPayoutReconciliationServiceInterface
{
    private const string LOG_NAME = 'instructor_payout_execution';

    public function __construct(
        private readonly InstructorPayoutExecutionServiceInterface $execution,
        private readonly InstructorPayoutProviderResolverInterface $resolver,
        private readonly InstructorEarningSettings $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function reconcileDue(int $limit = 200): int
    {
        if (! $this->settings->payout_reconciliation_enabled) {
            return 0;
        }

        $cutoff = now()->subMinutes(max(1, $this->settings->payout_unknown_timeout_minutes));

        $attempts = InstructorPayoutAttempt::query()
            ->reconciliationDue($cutoff)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $examined = 0;

        foreach ($attempts as $attempt) {
            $this->reconcileOne($attempt);
            $examined++;
        }

        return $examined;
    }

    public function reconcileAttempt(InstructorPayoutAttempt $attempt): InstructorPayoutAttempt
    {
        $this->reconcileOne($attempt);

        return InstructorPayoutAttempt::query()->whereKey($attempt->id)->firstOrFail();
    }

    private function reconcileOne(InstructorPayoutAttempt $attempt): void
    {
        if ($attempt->provider_payout_id === null) {
            $attempt->forceFill(['last_synced_at' => now()])->save();

            return;
        }

        try {
            $provider = $this->resolver->resolve($attempt->provider, $attempt->currency_code);
            $status = $provider->fetchStatus($attempt->provider_payout_id);
        } catch (PayoutProviderException $e) {
            $this->raiseIssue($attempt, PayoutReconciliationIssueType::ProviderUnavailable, PayoutReconciliationSeverity::Warning, $e->getMessage());
            $attempt->forceFill(['last_synced_at' => now()])->save();

            return;
        }

        $wasUnknown = $attempt->status === InstructorPayoutAttemptStatus::Unknown;
        $before = $attempt->status;

        $updated = $this->execution->applyProviderStatus($attempt, $status);

        if ($updated->status === $before && $before !== InstructorPayoutAttemptStatus::Unknown) {
            // Nothing changed and it was not already flagged unknown —
            // just record that we checked.
            InstructorPayoutAttempt::query()->whereKey($attempt->id)->update(['last_synced_at' => now()]);

            return;
        }

        if ($wasUnknown && $updated->status->isTerminal()) {
            $this->resolveOpenIssues($attempt, PayoutReconciliationIssueType::UnknownProviderOutcome, 'auto_reconciled', 'Provider outcome confirmed on a later reconciliation pass.');
        }

        if ($updated->status === InstructorPayoutAttemptStatus::Unknown && ! $wasUnknown) {
            $this->raiseIssue($attempt, PayoutReconciliationIssueType::LocalProviderStatusMismatch, PayoutReconciliationSeverity::Warning, 'Reconciliation could not confirm the provider outcome.');
        }
    }

    public function raiseIssue(
        InstructorPayoutAttempt $attempt,
        PayoutReconciliationIssueType $type,
        PayoutReconciliationSeverity $severity,
        string $safeSummary,
    ): InstructorPayoutReconciliationIssue {
        $existing = InstructorPayoutReconciliationIssue::query()
            ->open()
            ->where('withdrawal_request_id', $attempt->withdrawal_request_id)
            ->where('type', $type)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'last_detected_at' => now(),
                'severity' => $this->higherSeverity($existing->severity, $severity),
                'payout_attempt_id' => $attempt->id,
                'provider_status' => $attempt->provider_status,
                'local_status' => $attempt->status->value,
            ])->save();

            return $existing;
        }

        try {
            $issue = DB::transaction(function () use ($attempt, $type, $severity, $safeSummary): InstructorPayoutReconciliationIssue {
                $issue = InstructorPayoutReconciliationIssue::query()->create([
                    'reference' => $this->generateReference(),
                    'withdrawal_request_id' => $attempt->withdrawal_request_id,
                    'payout_attempt_id' => $attempt->id,
                    'provider' => $attempt->provider,
                    'type' => $type,
                    'severity' => $severity,
                    'local_status' => $attempt->status->value,
                    'provider_status' => $attempt->provider_status,
                    'amount_minor' => $attempt->amount_minor,
                    'currency_code' => $attempt->currency_code,
                    'safe_summary' => $safeSummary,
                    'first_detected_at' => now(),
                    'last_detected_at' => now(),
                ]);
                $issue->forceFill(['status' => PayoutReconciliationIssueStatus::Open])->save();

                return $issue;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent sweep won the race to create the same open
            // issue — the DB-level dedupe key (see the creating
            // migration) is the backstop; fetch and update it instead.
            $existing = InstructorPayoutReconciliationIssue::query()
                ->open()
                ->where('withdrawal_request_id', $attempt->withdrawal_request_id)
                ->where('type', $type)
                ->firstOrFail();
            $existing->forceFill(['last_detected_at' => now()])->save();

            return $existing;
        }

        $this->audit->logSystem(self::LOG_NAME, 'payout_reconciliation_issue_detected', sprintf('Reconciliation issue %s raised: %s', $issue->reference, $safeSummary), $issue, ['type' => $type->value, 'severity' => $severity->value]);
        InstructorPayoutReconciliationIssueDetected::dispatch($issue);

        return $issue;
    }

    public function assign(InstructorPayoutReconciliationIssue $issue, User $assignee, User $actor): InstructorPayoutReconciliationIssue
    {
        $issue->forceFill(['assigned_to' => $assignee->id])->save();

        $this->audit->logUser($actor, self::LOG_NAME, 'payout_reconciliation_issue_assigned', sprintf('Reconciliation issue %s assigned to %s.', $issue->reference, $assignee->name), $issue);

        return $issue;
    }

    public function startInvestigating(InstructorPayoutReconciliationIssue $issue, User $actor): InstructorPayoutReconciliationIssue
    {
        if ($issue->status !== PayoutReconciliationIssueStatus::Open) {
            throw new PayoutExecutionException('Only an open issue can move to investigating.');
        }

        $issue->forceFill(['status' => PayoutReconciliationIssueStatus::Investigating])->save();

        $this->audit->logUser($actor, self::LOG_NAME, 'payout_reconciliation_issue_investigating', sprintf('Reconciliation issue %s marked as investigating.', $issue->reference), $issue);

        return $issue;
    }

    public function resolve(InstructorPayoutReconciliationIssue $issue, User $actor, string $resolutionType, string $note): InstructorPayoutReconciliationIssue
    {
        if (trim($note) === '') {
            throw new PayoutExecutionException('A resolution note (the evidence) is required.');
        }

        if (! $issue->status->isOpen()) {
            throw new PayoutExecutionException('This issue is already closed.');
        }

        $issue->forceFill([
            'status' => PayoutReconciliationIssueStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
            'resolution_type' => $resolutionType,
            'resolution_note' => $note,
        ])->save();

        $this->audit->logOverride($actor, self::LOG_NAME, 'payout_reconciliation_issue_resolved', sprintf('Reconciliation issue %s resolved.', $issue->reference), $note, $issue, ['resolution_type' => $resolutionType]);
        InstructorPayoutReconciliationResolved::dispatch($issue);

        return $issue;
    }

    private function resolveOpenIssues(InstructorPayoutAttempt $attempt, PayoutReconciliationIssueType $type, string $resolutionType, string $note): void
    {
        InstructorPayoutReconciliationIssue::query()
            ->open()
            ->where('withdrawal_request_id', $attempt->withdrawal_request_id)
            ->where('type', $type)
            ->get()
            ->each(function (InstructorPayoutReconciliationIssue $issue) use ($resolutionType, $note): void {
                $issue->forceFill([
                    'status' => PayoutReconciliationIssueStatus::Resolved,
                    'resolved_at' => now(),
                    'resolution_type' => $resolutionType,
                    'resolution_note' => $note,
                ])->save();

                $this->audit->logSystem(self::LOG_NAME, 'payout_reconciliation_issue_resolved', sprintf('Reconciliation issue %s auto-resolved.', $issue->reference), $issue);
            });
    }

    private function higherSeverity(PayoutReconciliationSeverity $a, PayoutReconciliationSeverity $b): PayoutReconciliationSeverity
    {
        $rank = ['info' => 0, 'warning' => 1, 'critical' => 2];

        return $rank[$b->value] > $rank[$a->value] ? $b : $a;
    }

    private function generateReference(): string
    {
        do {
            $reference = sprintf('RI-%s-%s', now()->format('Ym'), strtoupper(Str::random(8)));
        } while (InstructorPayoutReconciliationIssue::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
