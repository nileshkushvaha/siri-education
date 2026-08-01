<?php

declare(strict_types=1);

namespace App\Dashboard\Services;

use App\Models\User;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\ReportDefinition;
use App\Reporting\Enums\ReportCategory;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The dashboard's single permission resolver. Two distinct mechanisms
 * exist in this codebase and mixing them up is the classic way to leak
 * data, so both are wrapped here exactly once:
 *
 *  - Report permissions go through {@see ReportAccessContextInterface},
 *    which already closes the Spatie `hasPermissionTo()` / `Gate::before`
 *    super-admin gap itself.
 *  - Everything else goes through Laravel's `Gate` via `$user->can()`,
 *    where `AppServiceProvider::registerSuperAdminGate()`'s `Gate::before`
 *    supplies the super-admin bypass.
 *
 * Callers must consult this BEFORE querying. The composition service
 * never fetches a restricted figure and then hides it — an unauthorised
 * section is simply not built.
 *
 * The four financial permissions are kept strictly separate here, as
 * `ReportAccessContext::canViewInstructorCompensation()` requires:
 * `ViewFinanceReports` must never imply `ViewInstructorCompensationReports`.
 */
final readonly class DashboardPermissions
{
    public function __construct(
        private ReportAccessContextInterface $access,
        private ReportRegistryInterface $registry,
    ) {}

    // ── Report-permission surface ────────────────────────────────────

    public function canViewReport(User $user, string $reportKey): bool
    {
        $definition = $this->registry->find($reportKey);

        return $definition !== null && $this->access->canView($user, $definition);
    }

    public function report(string $reportKey): ?ReportDefinition
    {
        return $this->registry->find($reportKey);
    }

    /** @return list<ReportDefinition> */
    public function availableReports(User $user): array
    {
        return $this->registry->availableFor($user);
    }

    /**
     * Gates metrics whose registry `requiredPermission` is
     * `ViewOperationalReports` alone — e.g. `disputed_lessons`,
     * `unfinalized_past_due_lessons`, read through the owning
     * repository.
     */
    public function canViewOperations(User $user): bool
    {
        return $this->canViewReport($user, 'booking_lesson_meeting_operations');
    }

    /**
     * Gates `BookingLessonMeetingOperationsReportService::bookingSummary()`
     * and `::lessonOutcomeSummary()`, which authorize on the report
     * definition AND additionally require `ViewBookingLessonReports`
     * (see that service's `authorizeBookingLesson()`). Calling them with
     * only the first permission throws an AuthorizationException, so the
     * dashboard must check both before it queries.
     */
    public function canViewOperationsSummaries(User $user): bool
    {
        return $this->canViewOperations($user)
            && $this->access->canViewCategory($user, ReportCategory::BookingsLessons);
    }

    public function canViewBookingLessonKpis(User $user): bool
    {
        return $this->canViewReport($user, 'booking_lesson_kpis');
    }

    public function canViewStudents(User $user): bool
    {
        return $this->canViewReport($user, 'student_engagement');
    }

    public function canViewInstructors(User $user): bool
    {
        return $this->canViewReport($user, 'instructor_performance');
    }

    public function canViewMarketplace(User $user): bool
    {
        return $this->canViewReport($user, 'marketplace_supply_demand');
    }

    public function canViewLearning(User $user): bool
    {
        return $this->canViewReport($user, 'learning_progress');
    }

    public function canViewReviewQuality(User $user): bool
    {
        return $this->canViewReport($user, 'review_quality_analytics');
    }

    public function canViewWallet(User $user): bool
    {
        return $this->canViewReport($user, 'wallet_activity');
    }

    public function canViewPayments(User $user): bool
    {
        return $this->canViewReport($user, 'payment_outcomes');
    }

    /**
     * Deliberately independent of every other financial permission —
     * instructor compensation stays more restrictive than general
     * finance reporting even for a user who holds `ViewFinanceReports`.
     */
    public function canViewInstructorCompensation(User $user): bool
    {
        return $this->canViewReport($user, 'earnings_settlements');
    }

    public function canViewRechargeMonitoring(User $user): bool
    {
        return $this->canViewReport($user, 'recharge_monitoring');
    }

    /** Student identity must be masked for a viewer without student-report access. */
    public function shouldMaskPersonalData(User $user): bool
    {
        return $this->access->shouldMaskPersonalData($user);
    }

    // ── Gate-permission surface ──────────────────────────────────────

    /**
     * A permission string that has never been seeded is a legitimate
     * state (an optional module may not be installed in every
     * environment), so an unknown ability resolves to "no", never to an
     * exception that would break the whole dashboard.
     */
    public function can(User $user, string $ability): bool
    {
        try {
            return $user->can($ability);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public function canViewOperationalAlerts(User $user): bool
    {
        return $this->can($user, 'ViewAny:OperationalAlert');
    }

    public function canViewLessons(User $user): bool
    {
        return $this->can($user, 'ViewAny:Lesson');
    }

    public function canViewSupportCases(User $user): bool
    {
        return $this->can($user, 'ViewAny:SupportCase');
    }

    /** Messaging's resource permission is `ViewAny:Messaging` — see `App\Policies\ConversationPolicy::viewAny()`. */
    public function canViewConversations(User $user): bool
    {
        return $this->can($user, 'ViewAny:Messaging');
    }

    /**
     * Suspicious-activity flags are restricted conservatively: the
     * dashboard surfaces them only to a super administrator, even
     * though a manager may hold the resource permission and reach the
     * resource itself through the sidebar.
     */
    public function canSeeComplianceOnDashboard(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function canViewPaymentReconciliation(User $user): bool
    {
        return $this->can($user, 'ViewAny:BookingPaymentReconciliationIssue');
    }

    public function canViewPayoutReconciliation(User $user): bool
    {
        return $this->can($user, 'ViewAny:InstructorPayoutReconciliationIssue');
    }

    public function canViewWithdrawals(User $user): bool
    {
        return $this->can($user, 'ViewAny:InstructorWithdrawalRequest');
    }

    // ── Quality surface ──────────────────────────────────────────────

    public function canViewQualityDashboard(User $user): bool
    {
        return $this->can($user, 'ViewQualityDashboard');
    }

    public function canViewReviewMetrics(User $user): bool
    {
        return $this->can($user, 'ViewReviewMetrics');
    }

    // ── System surface (super-admin strip) ───────────────────────────

    public function canViewQueueMonitor(User $user): bool
    {
        return $this->can($user, 'queue_monitor.view');
    }

    public function canViewSchedulerMonitor(User $user): bool
    {
        return $this->can($user, 'scheduler_monitor.view');
    }

    public function canViewCacheManager(User $user): bool
    {
        return $this->can($user, 'cache_manager.view');
    }

    /** True when the system strip should render at all. */
    public function canViewAnySystemHealth(User $user): bool
    {
        return $this->canViewQueueMonitor($user)
            || $this->canViewSchedulerMonitor($user)
            || $this->canViewCacheManager($user);
    }

    /**
     * A stable, order-independent digest of every permission decision
     * that shapes the composed dashboard. This is what makes the cache
     * key permission-scoped: two users with different access can never
     * collide on one entry, and granting a permission changes the
     * digest so stale output is not served.
     */
    public function signature(User $user): string
    {
        $flags = [
            'ops' => $this->canViewOperations($user),
            'bookings' => $this->canViewBookingLessonKpis($user),
            'students' => $this->canViewStudents($user),
            'instructors' => $this->canViewInstructors($user),
            'marketplace' => $this->canViewMarketplace($user),
            'learning' => $this->canViewLearning($user),
            'quality_reports' => $this->canViewReviewQuality($user),
            'quality_dashboard' => $this->canViewQualityDashboard($user),
            'review_metrics' => $this->canViewReviewMetrics($user),
            'wallet' => $this->canViewWallet($user),
            'payments' => $this->canViewPayments($user),
            'compensation' => $this->canViewInstructorCompensation($user),
            'recharge' => $this->canViewRechargeMonitoring($user),
            'mask_pii' => $this->shouldMaskPersonalData($user),
            'queue' => $this->canViewQueueMonitor($user),
            'scheduler' => $this->canViewSchedulerMonitor($user),
            'cache' => $this->canViewCacheManager($user),
            'reports' => implode(',', array_map(
                static fn (ReportDefinition $definition): string => $definition->key,
                $this->availableReports($user),
            )),
        ];

        ksort($flags);

        return substr(hash('sha256', json_encode($flags, JSON_THROW_ON_ERROR)), 0, 32);
    }
}
