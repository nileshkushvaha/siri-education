<?php

declare(strict_types=1);

namespace App\Compliance\Services;

use App\Compliance\Actions\TransitionSuspiciousActivityFlagStatusAction;
use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagDecision;
use App\Compliance\Enums\SuspiciousActivityFlagStatus;
use App\Compliance\Events\SuspiciousActivityFlagRecorded;
use App\Compliance\Exceptions\ComplianceValidationException;
use App\Compliance\Support\SuspiciousActivityFingerprint;
use App\Models\SuspiciousActivityFlag;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single authoritative writer of `suspicious_activity_flags` rows
 * and the only place their review lifecycle moves. No controller,
 * listener, job, or Filament action may write a flag directly — every
 * rule evaluator only ever builds a SuspiciousActivitySignal and hands
 * it to {@see record()}.
 *
 * A flag is evidence for human review, never proof of fraud —
 * nothing here ever suspends an account, blocks a payment, freezes a
 * wallet, cancels a booking, or reverses a reward.
 *
 * Deduplication is fingerprint-based and concurrency-safe: {@see
 * record()} always attempts to lock an existing ACTIVE row first,
 * then attempts an INSERT, and treats a unique-constraint violation
 * on `active_fingerprint` as proof a concurrent evaluation already won
 * — the loser locks and merges into the winner's row rather than
 * silently discarding the new occurrence. A repeat trigger against an
 * already-active flag increments `occurrence_count` and refreshes
 * `last_observed_at` ("merging"), never creating a second row. Once a
 * flag reaches a terminal status, its `active_fingerprint` is cleared
 * (see TransitionSuspiciousActivityFlagStatusAction) so the same
 * fingerprint is free to start a new episode later — but only after
 * its configured cooldown window has elapsed since the terminal
 * decision, so a flag an administrator just resolved or dismissed
 * cannot be immediately re-opened as a "new" flag by the very next
 * qualifying event.
 */
final class ComplianceMonitoringService
{
    private const string LOG_NAME = 'compliance';

    public function __construct(
        private readonly TransitionSuspiciousActivityFlagStatusAction $transition,
        private readonly AuditTrailService $audit,
    ) {}

    public function record(SuspiciousActivitySignal $signal): ?SuspiciousActivityFlag
    {
        return DB::transaction(function () use ($signal): ?SuspiciousActivityFlag {
            $fingerprint = SuspiciousActivityFingerprint::for($signal->ruleCode, $signal->subjectId);

            $active = SuspiciousActivityFlag::query()
                ->where('active_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($active !== null) {
                return $this->merge($active, $signal);
            }

            if ($this->suppressedByCooldown($fingerprint, $signal->cooldownMinutes)) {
                return null;
            }

            try {
                $flag = $this->create($signal, $fingerprint);
            } catch (UniqueConstraintViolationException) {
                // A concurrent evaluation already won the insert on
                // active_fingerprint's unique index — that IS the
                // idempotent outcome, not a failure. Lock and merge
                // into the winner's row instead of losing this
                // occurrence.
                $winner = SuspiciousActivityFlag::query()
                    ->where('active_fingerprint', $fingerprint)
                    ->lockForUpdate()
                    ->first();

                return $winner !== null ? $this->merge($winner, $signal) : null;
            }

            $this->audit->logSystem(
                self::LOG_NAME,
                'suspicious_activity_flag_created',
                sprintf('Suspicious activity flag %s (%s, %s) recorded for user %d.', $flag->reference, $flag->rule_code->value, $flag->severity->value, $flag->subject_id),
                $flag,
                [
                    'rule_code' => $flag->rule_code->value,
                    'category' => $flag->category->value,
                    'severity' => $flag->severity->value,
                    'subject_id' => $flag->subject_id,
                ],
            );

            SuspiciousActivityFlagRecorded::dispatch($flag);

            return $flag;
        });
    }

    public function startReview(SuspiciousActivityFlag $flag, User $admin): SuspiciousActivityFlag
    {
        $this->authorizeReview($admin, $flag, 'beginReview');

        return DB::transaction(function () use ($flag, $admin): SuspiciousActivityFlag {
            $flag = $this->lock($flag);

            if ($flag->status === SuspiciousActivityFlagStatus::InReview) {
                return $flag;
            }

            $previous = $flag->status;
            $flag = $this->transition->execute($flag, SuspiciousActivityFlagStatus::InReview, [
                'reviewer_id' => $admin->id,
            ]);

            $this->auditTransition($admin, 'suspicious_activity_flag_review_started', $flag, $previous, SuspiciousActivityFlagStatus::InReview, null);

            return $flag;
        });
    }

    public function resolve(SuspiciousActivityFlag $flag, User $admin, string $reason, SuspiciousActivityFlagDecision $decision): SuspiciousActivityFlag
    {
        $this->authorizeReview($admin, $flag, 'resolve');
        $this->assertReason($reason);

        return DB::transaction(function () use ($flag, $admin, $reason, $decision): SuspiciousActivityFlag {
            $flag = $this->lock($flag);

            if ($flag->status === SuspiciousActivityFlagStatus::Resolved) {
                return $flag; // idempotent repeat
            }

            $previous = $flag->status;
            $flag = $this->transition->execute($flag, SuspiciousActivityFlagStatus::Resolved, [
                'reviewer_id' => $flag->reviewer_id ?? $admin->id,
                'decision' => $decision,
                'decision_reason' => $this->sanitizeReason($reason),
                'reviewed_at' => now()->toImmutable()->utc(),
            ]);

            $this->auditTransition($admin, 'suspicious_activity_flag_resolved', $flag, $previous, SuspiciousActivityFlagStatus::Resolved, $reason);

            return $flag;
        });
    }

    public function dismiss(SuspiciousActivityFlag $flag, User $admin, string $reason): SuspiciousActivityFlag
    {
        $this->authorizeReview($admin, $flag, 'dismiss');
        $this->assertReason($reason);

        return DB::transaction(function () use ($flag, $admin, $reason): SuspiciousActivityFlag {
            $flag = $this->lock($flag);

            if ($flag->status === SuspiciousActivityFlagStatus::Dismissed) {
                return $flag; // idempotent repeat
            }

            $previous = $flag->status;
            $flag = $this->transition->execute($flag, SuspiciousActivityFlagStatus::Dismissed, [
                'reviewer_id' => $flag->reviewer_id ?? $admin->id,
                'decision_reason' => $this->sanitizeReason($reason),
                'reviewed_at' => now()->toImmutable()->utc(),
            ]);

            $this->auditTransition($admin, 'suspicious_activity_flag_dismissed', $flag, $previous, SuspiciousActivityFlagStatus::Dismissed, $reason);

            return $flag;
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function merge(SuspiciousActivityFlag $flag, SuspiciousActivitySignal $signal): SuspiciousActivityFlag
    {
        $flag->fill([
            'occurrence_count' => $flag->occurrence_count + 1,
            'last_observed_at' => $signal->occurredAt,
            'evidence' => $signal->evidence,
            'version' => $flag->version + 1,
        ])->save();

        $this->audit->logSystem(
            self::LOG_NAME,
            'suspicious_activity_flag_merged',
            sprintf('Suspicious activity flag %s (%s) occurrence #%d recorded.', $flag->reference, $flag->rule_code->value, $flag->occurrence_count),
            $flag,
            ['rule_code' => $flag->rule_code->value, 'occurrence_count' => $flag->occurrence_count],
        );

        return $flag;
    }

    private function create(SuspiciousActivitySignal $signal, string $fingerprint): SuspiciousActivityFlag
    {
        $id = (string) Str::uuid();

        return SuspiciousActivityFlag::query()->create([
            'id' => $id,
            'reference' => self::referenceFor($id),
            'rule_code' => $signal->ruleCode,
            'rule_version' => $signal->ruleVersion,
            'category' => $signal->ruleCode->category(),
            'severity' => $signal->severity,
            'status' => SuspiciousActivityFlagStatus::Open,
            'subject_id' => $signal->subjectId,
            'actor_id' => $signal->actorId,
            'occurrence_count' => 1,
            'first_observed_at' => $signal->occurredAt,
            'last_observed_at' => $signal->occurredAt,
            'evidence' => $signal->evidence,
            'threshold_snapshot' => $signal->thresholdSnapshot,
            'fingerprint' => $fingerprint,
            'active_fingerprint' => $fingerprint,
            'version' => 1,
        ]);
    }

    /**
     * A fingerprint whose most recent row is terminal and was decided
     * within its own cooldown window is not yet eligible to start a
     * new episode — this is the anti-flapping guard, distinct from
     * the observation window each rule already used to decide whether
     * to fire at all.
     */
    private function suppressedByCooldown(string $fingerprint, int $cooldownMinutes): bool
    {
        $recent = SuspiciousActivityFlag::query()
            ->where('fingerprint', $fingerprint)
            ->orderByDesc('created_at')
            ->first();

        if ($recent === null || $recent->status->isActive()) {
            return false;
        }

        $decidedAt = $recent->reviewed_at ?? $recent->updated_at;

        return $decidedAt !== null && $decidedAt->gt(now()->subMinutes($cooldownMinutes));
    }

    private static function referenceFor(string $id): string
    {
        return 'SAF-'.strtoupper(substr(str_replace('-', '', $id), 0, 10));
    }

    private function lock(SuspiciousActivityFlag $flag): SuspiciousActivityFlag
    {
        return SuspiciousActivityFlag::query()->whereKey($flag->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Independently enforced here (not only in the Policy) for the
     * same reason InstructorQualityAlertService excludes
     * self-resolution: Gate::before grants super_admin a global
     * bypass that skips the policy entirely, and roles are not
     * mutually exclusive.
     *
     * @throws AuthorizationException
     */
    private function authorizeReview(User $admin, SuspiciousActivityFlag $flag, string $ability): void
    {
        if ($admin->id === $flag->subject_id) {
            throw new AuthorizationException('You may not review a suspicious activity flag about your own conduct.');
        }

        if (! $admin->can($ability, $flag)) {
            throw new AuthorizationException('You may not review this suspicious activity flag.');
        }
    }

    /** @throws ComplianceValidationException */
    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new ComplianceValidationException('This review action requires a reason.');
        }
    }

    private function sanitizeReason(string $reason): string
    {
        return mb_substr(trim(strip_tags($reason)), 0, 500);
    }

    private function auditTransition(User $admin, string $event, SuspiciousActivityFlag $flag, SuspiciousActivityFlagStatus $previous, SuspiciousActivityFlagStatus $new, ?string $reason): void
    {
        $this->audit->logUser(
            $admin,
            self::LOG_NAME,
            $event,
            sprintf('Suspicious activity flag %s: %s → %s.', $flag->reference, $previous->value, $new->value),
            $flag,
            [
                'subject_id' => $flag->subject_id,
                'previous_status' => $previous->value,
                'new_status' => $new->value,
                'reason' => $reason,
                'version' => $flag->version,
            ],
        );
    }
}
