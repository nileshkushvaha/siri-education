<?php

declare(strict_types=1);

namespace App\Quality\Services;

use App\Models\InstructorQualityAlert;
use App\Models\User;
use App\Quality\Actions\TransitionInstructorQualityAlertStatusAction;
use App\Quality\Contracts\InstructorQualityAlertRepositoryInterface;
use App\Quality\Contracts\InstructorQualityAlertServiceInterface;
use App\Quality\DTOs\InstructorQualityAlertAdminData;
use App\Quality\Enums\InstructorQualityAlertResolutionAction;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Quality\Events\InstructorQualityAlertDismissed;
use App\Quality\Events\InstructorQualityAlertResolved;
use App\Quality\Events\InstructorQualityAlertReviewStarted;
use App\Quality\Exceptions\QualityAlertValidationException;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Administrative alert resolution — records a recommendation only.
 * Never touches instructor status, profile visibility, availability,
 * compensation, or marketplace ranking. A repeated identical
 * resolution (current status already equals the target) is an
 * idempotent no-op; a resolution that conflicts with a status already
 * reached another way throws, exactly like ReviewModerationService /
 * ReviewReportService.
 */
final class InstructorQualityAlertService implements InstructorQualityAlertServiceInterface
{
    private const string LOG_NAME = 'quality';

    public function __construct(
        private readonly InstructorQualityAlertRepositoryInterface $alerts,
        private readonly TransitionInstructorQualityAlertStatusAction $transition,
        private readonly AuditTrailService $audit,
    ) {}

    public function startReview(InstructorQualityAlert $alert, User $admin): InstructorQualityAlert
    {
        $this->authorizeResolve($admin, $alert);

        return DB::transaction(function () use ($alert, $admin): InstructorQualityAlert {
            $alert = $this->alerts->lock($alert);

            if ($alert->status === InstructorQualityAlertStatus::UnderReview) {
                return $alert;
            }

            $previous = $alert->status;
            $alert = $this->transition->execute($alert, InstructorQualityAlertStatus::UnderReview, [
                'reviewed_at' => now()->toImmutable()->utc(),
                'reviewed_by' => $admin->id,
            ]);

            $this->auditTransition($admin, 'instructor_quality_alert_review_started', $alert, $previous, InstructorQualityAlertStatus::UnderReview, null);
            InstructorQualityAlertReviewStarted::dispatch($alert);

            return $alert;
        });
    }

    public function resolve(InstructorQualityAlert $alert, User $admin, string $reason, InstructorQualityAlertResolutionAction $action = InstructorQualityAlertResolutionAction::NoAction): InstructorQualityAlert
    {
        $this->authorizeResolve($admin, $alert);
        $this->assertReason($reason);

        return DB::transaction(function () use ($alert, $admin, $reason, $action): InstructorQualityAlert {
            $alert = $this->alerts->lock($alert);

            if ($alert->status === InstructorQualityAlertStatus::Resolved) {
                return $alert; // idempotent repeat — never re-applies a differing recommendation
            }

            $previous = $alert->status;
            $alert = $this->transition->execute($alert, InstructorQualityAlertStatus::Resolved, [
                'reviewed_at' => $alert->reviewed_at ?? now()->toImmutable()->utc(),
                'reviewed_by' => $alert->reviewed_by ?? $admin->id,
                'resolved_at' => now()->toImmutable()->utc(),
                'resolution_reason' => $this->sanitizeReason($reason),
                'resolution_action' => $action,
            ]);

            $this->auditTransition($admin, 'instructor_quality_alert_resolved', $alert, $previous, InstructorQualityAlertStatus::Resolved, $reason);
            InstructorQualityAlertResolved::dispatch($alert);

            return $alert;
        });
    }

    public function dismiss(InstructorQualityAlert $alert, User $admin, string $reason): InstructorQualityAlert
    {
        $this->authorizeResolve($admin, $alert);
        $this->assertReason($reason);

        return DB::transaction(function () use ($alert, $admin, $reason): InstructorQualityAlert {
            $alert = $this->alerts->lock($alert);

            if ($alert->status === InstructorQualityAlertStatus::Dismissed) {
                return $alert;
            }

            $previous = $alert->status;
            $alert = $this->transition->execute($alert, InstructorQualityAlertStatus::Dismissed, [
                'reviewed_at' => $alert->reviewed_at ?? now()->toImmutable()->utc(),
                'reviewed_by' => $alert->reviewed_by ?? $admin->id,
                'resolved_at' => now()->toImmutable()->utc(),
                'resolution_reason' => $this->sanitizeReason($reason),
                'resolution_action' => InstructorQualityAlertResolutionAction::NoAction,
            ]);

            $this->auditTransition($admin, 'instructor_quality_alert_dismissed', $alert, $previous, InstructorQualityAlertStatus::Dismissed, $reason);
            InstructorQualityAlertDismissed::dispatch($alert);

            return $alert;
        });
    }

    public function markDuplicate(InstructorQualityAlert $alert, User $admin, ?string $reason = null): InstructorQualityAlert
    {
        $this->authorizeResolve($admin, $alert);

        return DB::transaction(function () use ($alert, $admin, $reason): InstructorQualityAlert {
            $alert = $this->alerts->lock($alert);

            if ($alert->status === InstructorQualityAlertStatus::Duplicate) {
                return $alert;
            }

            $previous = $alert->status;
            $alert = $this->transition->execute($alert, InstructorQualityAlertStatus::Duplicate, [
                'reviewed_at' => $alert->reviewed_at ?? now()->toImmutable()->utc(),
                'reviewed_by' => $alert->reviewed_by ?? $admin->id,
                'resolved_at' => now()->toImmutable()->utc(),
                'resolution_reason' => $this->sanitizeReason($reason),
                'resolution_action' => InstructorQualityAlertResolutionAction::NoAction,
            ]);

            $this->auditTransition($admin, 'instructor_quality_alert_marked_duplicate', $alert, $previous, InstructorQualityAlertStatus::Duplicate, $reason);

            return $alert;
        });
    }

    public function assign(InstructorQualityAlert $alert, User $admin, User $assignee): InstructorQualityAlert
    {
        $this->authorizeResolve($admin, $alert);

        return DB::transaction(function () use ($alert, $admin, $assignee): InstructorQualityAlert {
            $alert = $this->alerts->lock($alert);
            $alert->fill(['assigned_to' => $assignee->id, 'version' => $alert->version + 1])->save();

            $this->audit->logUser(
                $admin,
                self::LOG_NAME,
                'instructor_quality_alert_assigned',
                sprintf('Quality alert %s assigned to admin %d.', $alert->id, $assignee->id),
                $alert,
                ['assigned_to' => $assignee->id],
            );

            return $alert;
        });
    }

    public function adminProjection(InstructorQualityAlert $alert): InstructorQualityAlertAdminData
    {
        return InstructorQualityAlertAdminData::fromAlert($alert);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * The self-resolution exclusion is independently enforced here, not
     * only in InstructorQualityAlertPolicy — Gate::before grants
     * super_admin a global permission bypass that skips the policy
     * entirely, and Spatie roles are not mutually exclusive, so an
     * account holding both `instructor` and `super_admin` must still
     * never resolve a quality alert about their own conduct. Mirrors
     * the identical guard in ReviewModerationService::authorize() and
     * ReviewReportService::authorizeResolve().
     *
     * @throws AuthorizationException
     */
    private function authorizeResolve(User $admin, InstructorQualityAlert $alert): void
    {
        if ($admin->id === $alert->instructor_id) {
            throw new AuthorizationException('You may not review or resolve a quality alert about your own conduct.');
        }

        if (! $admin->can('resolve', $alert)) {
            throw new AuthorizationException('You may not review or resolve this quality alert.');
        }
    }

    /** @throws QualityAlertValidationException */
    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new QualityAlertValidationException('This resolution requires a reason.');
        }
    }

    private function sanitizeReason(?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return mb_substr(trim(strip_tags($reason)), 0, 500);
    }

    private function auditTransition(User $admin, string $event, InstructorQualityAlert $alert, InstructorQualityAlertStatus $previous, InstructorQualityAlertStatus $new, ?string $reason): void
    {
        $this->audit->logUser(
            $admin,
            self::LOG_NAME,
            $event,
            sprintf('Quality alert %s: %s → %s.', $alert->id, $previous->value, $new->value),
            $alert,
            [
                'instructor_id' => $alert->instructor_id,
                'previous_status' => $previous->value,
                'new_status' => $new->value,
                'reason' => $reason,
                'version' => $alert->version,
            ],
        );
    }
}
