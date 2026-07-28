<?php

declare(strict_types=1);

namespace App\SupportCases\Actions;

use App\Models\SupportCase;
use App\Models\User;
use App\Services\AuditTrailService;
use App\SupportCases\Enums\SupportCaseResolutionType;
use App\SupportCases\Enums\SupportCaseStatus;
use App\SupportCases\Events\SupportCaseStatusChanged;
use App\SupportCases\Exceptions\InvalidSupportCaseTransitionException;
use Illuminate\Support\Facades\DB;

/**
 * The sole writer of `support_cases.status` (SRS §25.9-25.10/§25.41
 * "Case status changes must be audit logged"). Every caller — the
 * frontend requester reopen path, and every Filament staff action —
 * goes through here, never a bare `$case->status = ...`.
 *
 * Row-locked inside its own transaction (mirrors
 * InvoiceNumberAllocator's allocate()) so two concurrent status
 * mutations (e.g. an admin resolving while another admin escalates)
 * can never both succeed against a stale in-memory status — the
 * second transaction re-reads the committed status under the lock and
 * is validated against the guard again before writing.
 */
final class TransitionSupportCaseStatusAction
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    public function execute(
        SupportCase $case,
        SupportCaseStatus $to,
        User $actor,
        ?string $reason = null,
        ?SupportCaseResolutionType $resolutionType = null,
        ?string $resolutionSummary = null,
    ): SupportCase {
        return DB::transaction(function () use ($case, $to, $actor, $reason, $resolutionType, $resolutionSummary): SupportCase {
            /** @var SupportCase $locked */
            $locked = SupportCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidSupportCaseTransitionException(sprintf(
                    'Support case %s cannot transition from %s to %s.',
                    $locked->case_number,
                    $from->label(),
                    $to->label(),
                ));
            }

            if ($to === SupportCaseStatus::Escalated && trim((string) $reason) === '') {
                throw new InvalidSupportCaseTransitionException('Escalation requires a reason.');
            }

            if ($to === SupportCaseStatus::Resolved && trim((string) $resolutionSummary) === '') {
                throw new InvalidSupportCaseTransitionException('Resolution requires a resolution summary.');
            }

            $attributes = ['status' => $to];

            if ($to === SupportCaseStatus::Resolved) {
                $attributes['resolved_at'] = now();
                $attributes['resolution_type'] = $resolutionType;
                $attributes['resolution_summary'] = $resolutionSummary;
            }

            if ($to === SupportCaseStatus::Closed) {
                $attributes['closed_at'] = now();
            }

            $locked->forceFill($attributes)->save();

            $this->audit->logUser(
                $actor,
                'support_cases',
                $this->auditEvent($to),
                sprintf('Support case %s moved from %s to %s.', $locked->case_number, $from->label(), $to->label()),
                $locked,
                array_filter([
                    'from' => $from->value,
                    'to' => $to->value,
                    'reason' => $reason,
                    'resolution_type' => $resolutionType?->value,
                ]),
            );

            SupportCaseStatusChanged::dispatch($locked, $from, $to, $actor, $reason);

            return $locked;
        });
    }

    private function auditEvent(SupportCaseStatus $to): string
    {
        return match ($to) {
            SupportCaseStatus::Escalated => 'case_escalated',
            SupportCaseStatus::Resolved => 'case_resolved',
            SupportCaseStatus::Closed => 'case_closed',
            SupportCaseStatus::Open => 'case_reopened',
            SupportCaseStatus::InProgress => 'case_in_progress',
            SupportCaseStatus::WaitingForUser => 'case_waiting_for_user',
        };
    }
}
