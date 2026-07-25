<?php

declare(strict_types=1);

namespace App\Alerts\Services;

use App\Alerts\Actions\TransitionOperationalAlertStatusAction;
use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertStatus;
use App\Alerts\Events\OperationalAlertRecorded;
use App\Alerts\Exceptions\OperationalAlertValidationException;
use App\Alerts\Support\OperationalAlertFingerprint;
use App\Models\OperationalAlert;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single authoritative writer of `operational_alerts` rows and the
 * only place their status moves (GAP-035, requirement #3). No
 * listener, job, controller, or Filament action may write an alert
 * directly — every source only ever builds an OperationalAlertSignal
 * and hands it to {@see createOrMerge()}.
 *
 * Deduplication mirrors ComplianceMonitoringService::record() exactly:
 * lock an existing active row for the fingerprint first, then attempt
 * an INSERT, treating a unique-constraint violation on
 * `active_fingerprint` as proof a concurrent source already won —
 * merge into the winner rather than losing the occurrence. Reopening
 * a resolved alert is deliberately NOT a status transition: Resolved
 * clears `active_fingerprint` (see TransitionOperationalAlertStatusAction),
 * so a later recurrence of the same fingerprint always starts a fresh
 * row (a new episode), never resurrecting the closed one — see the
 * migration's docblock for why.
 */
final class OperationalAlertService
{
    private const string LOG_NAME = 'operational_alerts';

    public function __construct(
        private readonly TransitionOperationalAlertStatusAction $transition,
        private readonly AuditTrailService $audit,
    ) {}

    public function createOrMerge(OperationalAlertSignal $signal): OperationalAlert
    {
        return DB::transaction(function () use ($signal): OperationalAlert {
            $fingerprint = OperationalAlertFingerprint::for($signal->type, $signal->subjectType, $signal->subjectId);

            $active = OperationalAlert::query()
                ->where('active_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($active !== null) {
                return $this->merge($active, $signal);
            }

            try {
                $alert = $this->create($signal, $fingerprint);
            } catch (UniqueConstraintViolationException) {
                // A concurrent source already won the insert on
                // active_fingerprint's unique index — the idempotent
                // outcome, not a failure. Lock and merge into the
                // winner instead of losing this occurrence.
                $winner = OperationalAlert::query()
                    ->where('active_fingerprint', $fingerprint)
                    ->lockForUpdate()
                    ->first();

                return $winner !== null ? $this->merge($winner, $signal) : $this->create($signal, $fingerprint);
            }

            $this->audit->logSystem(
                self::LOG_NAME,
                'operational_alert_created',
                sprintf('Operational alert %s (%s, %s) recorded.', $alert->reference, $alert->type->value, $alert->severity->value),
                $alert,
                ['type' => $alert->type->value, 'category' => $alert->category->value, 'severity' => $alert->severity->value],
            );

            OperationalAlertRecorded::dispatch($alert);

            return $alert;
        });
    }

    public function acknowledge(OperationalAlert $alert, User $admin): OperationalAlert
    {
        $this->authorize($admin, 'acknowledge', $alert);

        return DB::transaction(function () use ($alert, $admin): OperationalAlert {
            $alert = $this->lock($alert);

            if ($alert->status === OperationalAlertStatus::Acknowledged) {
                return $alert; // idempotent repeat
            }

            $previous = $alert->status;
            $alert = $this->transition->execute($alert, OperationalAlertStatus::Acknowledged, [
                'acknowledged_by' => $admin->id,
                'acknowledged_at' => now()->toImmutable()->utc(),
            ]);

            $this->auditTransition($admin, 'operational_alert_acknowledged', $alert, $previous, OperationalAlertStatus::Acknowledged, null);

            return $alert;
        });
    }

    public function resolve(OperationalAlert $alert, User $admin, string $reason): OperationalAlert
    {
        $this->authorize($admin, 'resolve', $alert);
        $this->assertReason($reason);

        return DB::transaction(function () use ($alert, $admin, $reason): OperationalAlert {
            $alert = $this->lock($alert);

            if ($alert->status === OperationalAlertStatus::Resolved) {
                return $alert; // idempotent repeat
            }

            $previous = $alert->status;
            $alert = $this->transition->execute($alert, OperationalAlertStatus::Resolved, [
                'resolved_by' => $admin->id,
                'resolved_at' => now()->toImmutable()->utc(),
                'resolution_reason' => $this->sanitizeReason($reason),
            ]);

            $this->auditTransition($admin, 'operational_alert_resolved', $alert, $previous, OperationalAlertStatus::Resolved, $reason);

            return $alert;
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function merge(OperationalAlert $alert, OperationalAlertSignal $signal): OperationalAlert
    {
        $alert->fill([
            'occurrence_count' => $alert->occurrence_count + 1,
            'last_observed_at' => $signal->occurredAt ?? now(),
            'severity' => $alert->severity->higherOf($signal->severity),
            'metadata' => $signal->metadata,
            'version' => $alert->version + 1,
        ])->save();

        $this->audit->logSystem(
            self::LOG_NAME,
            'operational_alert_merged',
            sprintf('Operational alert %s (%s) occurrence #%d recorded.', $alert->reference, $alert->type->value, $alert->occurrence_count),
            $alert,
            ['type' => $alert->type->value, 'occurrence_count' => $alert->occurrence_count],
        );

        return $alert;
    }

    private function create(OperationalAlertSignal $signal, string $fingerprint): OperationalAlert
    {
        $id = (string) Str::uuid();
        $occurredAt = $signal->occurredAt ?? now();

        return OperationalAlert::query()->create([
            'id' => $id,
            'reference' => self::referenceFor($id),
            'type' => $signal->type,
            'category' => $signal->category,
            'severity' => $signal->severity,
            'status' => OperationalAlertStatus::Open,
            'title' => $signal->title,
            'summary' => $signal->summary,
            'subject_type' => $signal->subjectType,
            'subject_id' => $signal->subjectId,
            'fingerprint' => $fingerprint,
            'active_fingerprint' => $fingerprint,
            'occurrence_count' => 1,
            'first_observed_at' => $occurredAt,
            'last_observed_at' => $occurredAt,
            'metadata' => $signal->metadata,
            'version' => 1,
        ]);
    }

    private static function referenceFor(string $id): string
    {
        return 'OPS-'.strtoupper(substr(str_replace('-', '', $id), 0, 10));
    }

    private function lock(OperationalAlert $alert): OperationalAlert
    {
        return OperationalAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
    }

    /** @throws AuthorizationException */
    private function authorize(User $admin, string $ability, OperationalAlert $alert): void
    {
        if (! $admin->can($ability, $alert)) {
            throw new AuthorizationException('You may not act on this operational alert.');
        }
    }

    /** @throws OperationalAlertValidationException */
    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new OperationalAlertValidationException('Resolving an operational alert requires a reason.');
        }
    }

    private function sanitizeReason(string $reason): string
    {
        return mb_substr(trim(strip_tags($reason)), 0, 500);
    }

    private function auditTransition(User $admin, string $event, OperationalAlert $alert, OperationalAlertStatus $previous, OperationalAlertStatus $new, ?string $reason): void
    {
        $this->audit->logUser(
            $admin,
            self::LOG_NAME,
            $event,
            sprintf('Operational alert %s: %s → %s.', $alert->reference, $previous->value, $new->value),
            $alert,
            [
                'previous_status' => $previous->value,
                'new_status' => $new->value,
                'reason' => $reason,
                'version' => $alert->version,
            ],
        );
    }
}
