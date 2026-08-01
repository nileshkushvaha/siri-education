<?php

declare(strict_types=1);

namespace App\Dashboard\Services;

use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertStatus;
use App\Alerts\Enums\OperationalAlertType;
use App\Compliance\Enums\SuspiciousActivityFlagStatus;
use App\Dashboard\DTOs\AttentionFeed;
use App\Dashboard\DTOs\AttentionItem;
use App\Dashboard\DTOs\DashboardContext;
use App\Dashboard\Enums\AttentionSeverity;
use App\Dashboard\Support\DashboardUrl;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Filament\Resources\BookingPaymentReconciliationIssues\BookingPaymentReconciliationIssueResource;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use App\Filament\Resources\InstructorPayoutReconciliationIssues\InstructorPayoutReconciliationIssueResource;
use App\Filament\Resources\InstructorWithdrawalRequests\InstructorWithdrawalRequestResource;
use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\OperationalAlerts\OperationalAlertResource;
use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Filament\Resources\SuspiciousActivityFlags\SuspiciousActivityFlagResource;
use App\Lessons\Enums\LessonStatus;
use App\Messaging\Enums\MessageReportStatus;
use App\Models\BookingPaymentReconciliationIssue;
use App\Models\InstructorPayoutReconciliationIssue;
use App\Models\InstructorWithdrawalRequest;
use App\Models\MessageReport;
use App\Models\OperationalAlert;
use App\Models\SupportCase;
use App\Models\SuspiciousActivityFlag;
use App\Models\User;
use App\Quality\Contracts\AdminQualityDashboardServiceInterface;
use App\Quality\DTOs\QualityDashboardSummaryData;
use App\Reporting\Contracts\LearningAnalyticsReportServiceInterface;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\InstructorFinancialReportRepository;
use App\Reporting\Repositories\LessonOperationsRepository;
use App\Reporting\Repositories\WalletFinancialReportRepository;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Services\Instructor\InstructorOnboardingService;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Builds the Needs Attention section.
 *
 * Three rules shape everything here:
 *
 * 1. **Nothing period-scoped.** Attention answers "what needs action
 *    right now", so the dashboard's period selector must never change
 *    these numbers. Where an authoritative calculation genuinely has no
 *    current-state form (`unfinalizedPastDueCount` is scoped by
 *    `starts_at`), a FIXED rolling 30-day window is used and the card
 *    labels itself accordingly — it still does not move with the
 *    selector.
 *
 * 2. **Permission before query.** Every builder returns null unless the
 *    viewer is authorised, so a restricted category's count query never
 *    executes. There is no "fetch then hide".
 *
 * 3. **Meeting problems are counted once.** Operational alerts are the
 *    authoritative source for meeting-creation failure and missing
 *    meeting links, so this feed PARTITIONS the open-alert set into a
 *    lesson-access card and an everything-else card, and never adds a
 *    second meeting card derived from the Operations report. The
 *    Operations report keeps its own meeting metrics; the dashboard
 *    simply does not restate them.
 *
 * Counts are reused from their existing owners wherever an owner
 * exists: the reporting repositories the `MetricRegistry` names as
 * `calculationOwner`, `AdminQualityDashboardService`, and
 * `InstructorOnboardingResource::pendingReviewQuery()`. Operational
 * alerts, support cases, message reports and compliance flags have no
 * registered report at all, so each resource's navigation-badge
 * predicate is the authoritative admin definition and is mirrored
 * exactly rather than reinvented — the sidebar badge and the dashboard
 * card can never disagree.
 */
final readonly class AttentionFeedService
{
    /**
     * Urgent counts get a deliberately short TTL — long enough to
     * absorb a page refresh, short enough that an operator never acts
     * on a materially stale alert count. The period-scoped composition
     * uses a much longer TTL; the two are never merged.
     */
    public const int CACHE_TTL_SECONDS = 60;

    /** Alert types that block a participant from reaching their lesson. */
    private const array LESSON_ACCESS_ALERT_TYPES = [
        OperationalAlertType::MeetingCreationFailed,
        OperationalAlertType::MeetingCancellationFailed,
        OperationalAlertType::MissingMeetingLink,
    ];

    public function __construct(
        private DashboardPermissions $permissions,
        private LessonOperationsRepository $lessons,
        private WalletFinancialReportRepository $wallet,
        private InstructorFinancialReportRepository $instructorFinancials,
        private AdminQualityDashboardServiceInterface $quality,
        private LearningAnalyticsReportServiceInterface $learning,
        private InstructorOnboardingService $onboarding,
        private CacheRepository $cache,
    ) {}

    public function build(User $user): AttentionFeed
    {
        $timezone = ReportingTimezoneResolver::resolve();

        /** @var array{items: list<array<string, mixed>>, generated_at: string} $payload */
        $payload = $this->cache->remember(
            $this->cacheKey($user),
            self::CACHE_TTL_SECONDS,
            fn (): array => [
                'items' => array_map(
                    static fn (AttentionItem $item): array => $item->toArray(),
                    $this->collect($user),
                ),
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
            ],
        );

        return new AttentionFeed(
            items: array_map(AttentionItem::fromArray(...), $payload['items']),
            generatedAt: CarbonImmutable::parse($payload['generated_at']),
            reportingTimezone: $timezone,
            // Honest: this section is cached, briefly. It never claims Live.
            freshness: ReportDataFreshness::CachedWithTimestamp,
            overflowUrl: $this->permissions->canViewOperationalAlerts($user)
                ? DashboardUrl::resourceIndex(OperationalAlertResource::class, ['status' => OperationalAlertStatus::Open->value])
                : null,
        );
    }

    /**
     * Permission-scoped so one user's visible categories can never be
     * served to another. The user id alone would not be enough — a
     * permission change must also invalidate.
     */
    private function cacheKey(User $user): string
    {
        return sprintf('dashboard:attention:%s:%s', $user->getKey(), $this->permissions->signature($user));
    }

    /** @return list<AttentionItem> */
    private function collect(User $user): array
    {
        // One quality summary feeds both quality cards. Fetched once
        // here and threaded through rather than called twice — the same
        // "no duplicate service call for figures from one DTO" rule the
        // composition service follows.
        $quality = $this->permissions->canViewQualityDashboard($user)
            ? $this->quality->summary()
            : null;

        $items = array_filter([
            $this->lessonAccessAlerts($user),
            $this->otherOperationalAlerts($user),
            $this->walletLedgerIntegrity($user),
            $this->settlementIntegrity($user),
            $this->rechargeCreditFailures($user),
            $this->paymentReconciliation($user),
            $this->payoutReconciliation($user),
            $this->criticalSupportCases($user),
            $this->disputedLessons($user),
            $this->unfinalizedPastDueLessons($user),
            $this->withdrawalsAwaitingReview($user),
            $this->instructorApplications($user),
            $this->qualityAlerts($quality),
            $this->reviewModeration($quality),
            $this->messageReports($user),
            $this->overdueHomework($user),
            $this->plansReviewDue($user),
            $this->suspiciousActivity($user),
        ]);

        $items = array_values(array_filter(
            $items,
            static fn (AttentionItem $item): bool => $item->shouldRender(),
        ));

        usort($items, static fn (AttentionItem $a, AttentionItem $b): int => $a->sortKey() <=> $b->sortKey());

        return $items;
    }

    // ── Operational alerts (authoritative for meeting problems) ──────

    private function lessonAccessAlerts(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewOperationalAlerts($user)) {
            return null;
        }

        $count = OperationalAlert::query()
            ->where('status', OperationalAlertStatus::Open->value)
            ->whereIn('type', $this->lessonAccessTypeValues())
            ->count();

        return new AttentionItem(
            key: 'lesson_access_alerts',
            label: 'Lesson access at risk',
            count: $count,
            severity: AttentionSeverity::Critical,
            explanation: 'Open alerts where a meeting could not be created or cancelled, or has no link — a participant cannot join.',
            icon: 'heroicon-o-video-camera-slash',
            url: DashboardUrl::resourceIndex(OperationalAlertResource::class, [
                'status' => OperationalAlertStatus::Open->value,
                'category' => OperationalAlertCategory::BookingMeeting->value,
            ]),
            destinationLabel: 'Operational alerts — meetings',
            // Highest weight inside Critical: a lesson nobody can join
            // outranks a reconciliation discrepancy.
            tieBreaker: 5,
        );
    }

    private function otherOperationalAlerts(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewOperationalAlerts($user)) {
            return null;
        }

        $base = OperationalAlert::query()
            ->where('status', OperationalAlertStatus::Open->value)
            ->whereNotIn('type', $this->lessonAccessTypeValues());

        $count = (clone $base)->count();
        $urgent = (clone $base)
            ->whereIn('severity', [
                OperationalAlertSeverity::Critical->value,
                OperationalAlertSeverity::High->value,
            ])
            ->count();

        return new AttentionItem(
            key: 'operational_alerts',
            label: 'Open operational alerts',
            count: $count,
            severity: $urgent > 0 ? AttentionSeverity::Critical : AttentionSeverity::Warning,
            explanation: $urgent > 0
                ? sprintf('%d at high or critical severity. Meeting problems are counted separately above.', $urgent)
                : 'Automated cross-domain failures awaiting acknowledgement. Meeting problems are counted separately.',
            icon: 'heroicon-o-signal-slash',
            url: DashboardUrl::resourceIndex(OperationalAlertResource::class, ['status' => OperationalAlertStatus::Open->value]),
            destinationLabel: 'Operational alerts',
            tieBreaker: 20,
        );
    }

    /** @return list<string> */
    private function lessonAccessTypeValues(): array
    {
        return array_map(
            static fn (OperationalAlertType $type): string => $type->value,
            self::LESSON_ACCESS_ALERT_TYPES,
        );
    }

    // ── Financial integrity (hard-zero invariants) ───────────────────

    private function walletLedgerIntegrity(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewWallet($user)) {
            return null;
        }

        // MetricRegistry names WalletFinancialReportRepository::balanceMismatchCount
        // as this metric's calculationOwner — reused, never recomputed.
        $count = $this->wallet->balanceMismatchCount();

        return new AttentionItem(
            key: 'wallet_ledger_mismatch',
            label: 'Wallet balance / ledger integrity',
            count: $count,
            severity: AttentionSeverity::Critical,
            explanation: $count > 0
                ? 'Stored wallet balances disagree with their latest ledger entry. Read-only signal — never repaired from a report.'
                : 'Every wallet balance agrees with its latest ledger entry.',
            icon: 'heroicon-o-scale',
            url: DashboardUrl::walletRefunds($this->fixedContext()),
            destinationLabel: 'Wallet & Refunds',
            tieBreaker: 10,
            isIntegritySignal: true,
        );
    }

    private function settlementIntegrity(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewInstructorCompensation($user)) {
            return null;
        }

        $count = $this->instructorFinancials->settlementAllocationMismatchCount();

        return new AttentionItem(
            key: 'settlement_mismatch',
            label: 'Settlement allocation integrity',
            count: $count,
            severity: AttentionSeverity::Critical,
            explanation: $count > 0
                ? 'Settlement batch totals disagree with the sum of their allocated earnings.'
                : 'Every settlement batch total matches its allocated earnings.',
            icon: 'heroicon-o-banknotes',
            url: DashboardUrl::instructorFinancials($this->fixedContext()),
            destinationLabel: 'Instructor financials',
            tieBreaker: 12,
            isIntegritySignal: true,
        );
    }

    private function rechargeCreditFailures(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewRechargeMonitoring($user)) {
            return null;
        }

        $summary = $this->wallet->rechargeMonitoringSummary();
        $count = $summary->capturedCreditFailed + $summary->stale;

        return new AttentionItem(
            key: 'recharge_credit_failures',
            label: 'Recharges captured but not credited',
            count: $count,
            severity: AttentionSeverity::Critical,
            explanation: sprintf(
                '%d credit failure(s), %d stale attempt(s). A student has paid but their wallet was not credited.',
                $summary->capturedCreditFailed,
                $summary->stale,
            ),
            icon: 'heroicon-o-exclamation-triangle',
            url: DashboardUrl::rechargeMonitoring(),
            destinationLabel: 'Recharge monitoring',
            tieBreaker: 8,
        );
    }

    private function paymentReconciliation(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewPaymentReconciliation($user)) {
            return null;
        }

        // Mirrors BookingPaymentReconciliationIssueResource::getNavigationBadge().
        $count = BookingPaymentReconciliationIssue::query()->open()->count();

        return new AttentionItem(
            key: 'payment_reconciliation',
            label: 'Payment reconciliation issues',
            count: $count,
            severity: AttentionSeverity::High,
            explanation: 'Payments whose local state could not be reconciled with the provider.',
            icon: 'heroicon-o-credit-card',
            url: DashboardUrl::resourceIndex(BookingPaymentReconciliationIssueResource::class, ['status' => 'open']),
            destinationLabel: 'Payment reconciliation',
            tieBreaker: 10,
        );
    }

    private function payoutReconciliation(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewPayoutReconciliation($user)) {
            return null;
        }

        $count = InstructorPayoutReconciliationIssue::query()->open()->count();

        return new AttentionItem(
            key: 'payout_reconciliation',
            label: 'Payout reconciliation issues',
            count: $count,
            severity: AttentionSeverity::High,
            explanation: 'Instructor payouts whose local state could not be reconciled with the provider.',
            icon: 'heroicon-o-arrow-up-tray',
            url: DashboardUrl::resourceIndex(InstructorPayoutReconciliationIssueResource::class, ['status' => 'open']),
            destinationLabel: 'Payout reconciliation',
            tieBreaker: 12,
        );
    }

    // ── Operational workload ─────────────────────────────────────────

    private function criticalSupportCases(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewSupportCases($user)) {
            return null;
        }

        // Mirrors SupportCaseResource::getNavigationBadge() exactly.
        $count = SupportCase::query()
            ->where('priority', SupportCasePriority::Critical)
            ->whereNotIn('status', [SupportCaseStatus::Resolved, SupportCaseStatus::Closed])
            ->count();

        return new AttentionItem(
            key: 'critical_support_cases',
            label: 'Critical support cases',
            count: $count,
            severity: AttentionSeverity::Critical,
            explanation: 'Unresolved cases raised at critical priority.',
            icon: 'heroicon-o-lifebuoy',
            url: DashboardUrl::resourceIndex(SupportCaseResource::class, ['priority' => SupportCasePriority::Critical->value]),
            destinationLabel: 'Support cases',
            tieBreaker: 30,
        );
    }

    private function disputedLessons(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewOperations($user)) {
            return null;
        }

        // disputedCount() is explicitly current-state, not period-scoped
        // (see LessonOperationsRepository's docblock) — exactly what an
        // attention card requires.
        $count = $this->lessons->disputedCount($this->neutralFilters());

        $canOpenLessons = $this->permissions->canViewLessons($user);

        return new AttentionItem(
            key: 'disputed_lessons',
            label: 'Disputed lessons',
            count: $count,
            severity: AttentionSeverity::High,
            explanation: 'Lessons under active dispute. A dispute can reopen any no-show or technical-issue decision.',
            icon: 'heroicon-o-scale',
            url: $canOpenLessons
                ? DashboardUrl::resourceIndex(LessonResource::class, ['status' => LessonStatus::Disputed->value])
                : DashboardUrl::operations($this->fixedContext()),
            destinationLabel: $canOpenLessons ? 'Disputed lessons' : 'Operations report',
            tieBreaker: 20,
        );
    }

    private function unfinalizedPastDueLessons(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewOperations($user)) {
            return null;
        }

        // The authoritative calculation is scoped by `starts_at`, so a
        // window is unavoidable. A FIXED rolling 30 days is used — never
        // the dashboard's selected period — so this card cannot move
        // when the selector changes, and its label states the window.
        $period = $this->fixedPeriod();
        $count = $this->lessons->unfinalizedPastDueCount($period, new ReportFilters(period: $period));

        return new AttentionItem(
            key: 'unfinalized_past_due_lessons',
            label: 'Lessons stuck without an outcome',
            count: $count,
            severity: AttentionSeverity::High,
            explanation: 'Lessons whose end time has passed with no finalized outcome. Automated finalization may be disabled.',
            icon: 'heroicon-o-clock',
            url: DashboardUrl::operations($this->fixedContext()),
            destinationLabel: 'Operations report',
            tieBreaker: 25,
            asOfLabel: 'Rolling 30 days',
        );
    }

    private function withdrawalsAwaitingReview(User $user): ?AttentionItem
    {
        // Two independent gates: the compensation report permission AND
        // the resource permission. Neither alone may expose instructor
        // payout amounts on the dashboard.
        if (! $this->permissions->canViewInstructorCompensation($user) || ! $this->permissions->canViewWithdrawals($user)) {
            return null;
        }

        // Mirrors InstructorWithdrawalRequestResource::getNavigationBadge().
        $count = InstructorWithdrawalRequest::query()
            ->whereIn('status', [
                InstructorWithdrawalStatus::Submitted->value,
                InstructorWithdrawalStatus::UnderReview->value,
            ])
            ->count();

        return new AttentionItem(
            key: 'withdrawals_awaiting_review',
            label: 'Withdrawals awaiting review',
            count: $count,
            severity: AttentionSeverity::Warning,
            explanation: 'Instructors waiting on a withdrawal decision.',
            icon: 'heroicon-o-inbox-arrow-down',
            url: DashboardUrl::resourceIndex(InstructorWithdrawalRequestResource::class, [
                'status' => InstructorWithdrawalStatus::Submitted->value,
            ]),
            destinationLabel: 'Withdrawal requests',
            tieBreaker: 10,
        );
    }

    private function instructorApplications(User $user): ?AttentionItem
    {
        if (! $this->onboarding->canReviewApplications($user)) {
            return null;
        }

        // pendingReviewQuery() is the resource's own authoritative
        // definition of "needs review" (four lifecycle statuses) and
        // backs its navigation badge — reused verbatim.
        $count = InstructorOnboardingResource::pendingReviewQuery()->count();

        return new AttentionItem(
            key: 'instructor_applications',
            label: 'Instructor applications to review',
            count: $count,
            severity: AttentionSeverity::Warning,
            explanation: 'Applications submitted, under review, awaiting documents, or awaiting interview.',
            icon: 'heroicon-o-identification',
            // The list page's `needs_review` tab matches pendingReviewQuery()
            // exactly, and ListRecords binds `activeTab` to `?tab=`.
            url: DashboardUrl::resourceIndex(InstructorOnboardingResource::class, tab: 'needs_review'),
            destinationLabel: 'Instructor onboarding',
            tieBreaker: 20,
        );
    }

    // ── Quality and learning ─────────────────────────────────────────

    private function qualityAlerts(?QualityDashboardSummaryData $summary): ?AttentionItem
    {
        // Null summary means the viewer was not authorised, so the
        // summary query was never issued in the first place.
        if ($summary === null) {
            return null;
        }

        $count = $summary->openAlerts + $summary->alertsUnderReview;
        $urgent = $summary->criticalSeverityAlerts + $summary->highSeverityAlerts;

        return new AttentionItem(
            key: 'quality_alerts',
            label: 'Instructor quality alerts',
            count: $count,
            severity: $urgent > 0 ? AttentionSeverity::High : AttentionSeverity::Warning,
            explanation: $urgent > 0
                ? sprintf('%d at high or critical severity.', $urgent)
                : 'Instructors flagged by automated quality detectors.',
            icon: 'heroicon-o-flag',
            url: DashboardUrl::reviewsQuality(),
            destinationLabel: 'Reviews & Quality',
            tieBreaker: 40,
        );
    }

    private function reviewModeration(?QualityDashboardSummaryData $summary): ?AttentionItem
    {
        if ($summary === null) {
            return null;
        }

        $count = $summary->submittedReviews + $summary->flaggedReviews;

        return new AttentionItem(
            key: 'review_moderation',
            label: 'Reviews awaiting moderation',
            count: $count,
            severity: AttentionSeverity::Warning,
            explanation: sprintf('%d submitted, %d flagged.', $summary->submittedReviews, $summary->flaggedReviews),
            icon: 'heroicon-o-inbox-stack',
            url: DashboardUrl::reviewsQuality(),
            destinationLabel: 'Reviews & Quality',
            tieBreaker: 45,
        );
    }

    private function messageReports(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewConversations($user)) {
            return null;
        }

        // Mirrors ConversationResource::getNavigationBadge().
        $count = MessageReport::query()->where('status', MessageReportStatus::Pending)->count();

        return new AttentionItem(
            key: 'message_reports',
            label: 'Reported messages',
            count: $count,
            severity: AttentionSeverity::Warning,
            explanation: 'Messages reported by a student or instructor and awaiting review.',
            icon: 'heroicon-o-chat-bubble-left-ellipsis',
            url: ConversationResource::getUrl('index'),
            destinationLabel: 'Conversations',
            tieBreaker: 50,
        );
    }

    private function overdueHomework(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewLearning($user)) {
            return null;
        }

        // `homework_currently_overdue` is a current-state metric. Its
        // owning service still takes a period for its other figures, so
        // a fixed neutral period is supplied; the overdue count is
        // unaffected by it.
        $summary = $this->learning->homeworkSummary($user, $this->fixedPeriod(), $this->neutralFilters());

        return new AttentionItem(
            key: 'homework_overdue',
            label: 'Homework overdue',
            count: $summary->currentlyOverdue,
            severity: AttentionSeverity::Info,
            explanation: 'Assignments past their due date with no submission.',
            icon: 'heroicon-o-document-text',
            url: DashboardUrl::learningAnalytics($this->fixedContext(), section: 'homework'),
            destinationLabel: 'Learning Analytics — Homework',
            tieBreaker: 10,
        );
    }

    private function plansReviewDue(User $user): ?AttentionItem
    {
        if (! $this->permissions->canViewLearning($user)) {
            return null;
        }

        $summary = $this->learning->milestoneReviewSummary($user, $this->fixedPeriod(), $this->neutralFilters());

        return new AttentionItem(
            key: 'plans_review_due',
            label: 'Learning plans review-due',
            count: $summary->plansCurrentlyReviewDue,
            severity: AttentionSeverity::Info,
            explanation: 'Active learning plans whose scheduled progress review is now due.',
            icon: 'heroicon-o-clipboard-document-check',
            url: DashboardUrl::learningAnalytics($this->fixedContext(), section: 'plans'),
            destinationLabel: 'Learning Analytics — Plans',
            tieBreaker: 15,
        );
    }

    // ── Compliance (deliberately super-admin only on the dashboard) ──

    private function suspiciousActivity(User $user): ?AttentionItem
    {
        if (! $this->permissions->canSeeComplianceOnDashboard($user)) {
            return null;
        }

        $count = SuspiciousActivityFlag::query()
            ->where('status', SuspiciousActivityFlagStatus::Open->value)
            ->count();

        return new AttentionItem(
            key: 'suspicious_activity',
            label: 'Suspicious activity flags',
            count: $count,
            severity: AttentionSeverity::High,
            explanation: 'Automated fraud and abuse signals awaiting a decision.',
            icon: 'heroicon-o-shield-exclamation',
            url: DashboardUrl::resourceIndex(SuspiciousActivityFlagResource::class, [
                'status' => SuspiciousActivityFlagStatus::Open->value,
            ]),
            destinationLabel: 'Suspicious activity flags',
            tieBreaker: 30,
        );
    }

    // ── Shared helpers ───────────────────────────────────────────────

    /**
     * A fixed period, used only to satisfy signatures whose
     * current-state figures ignore it. Never the user's selected
     * period, so no attention count can move with the selector.
     */
    private function fixedPeriod(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, ReportingTimezoneResolver::resolve());
    }

    private function neutralFilters(): ReportFilters
    {
        return new ReportFilters(period: $this->fixedPeriod());
    }

    private function fixedContext(): DashboardContext
    {
        return new DashboardContext(period: $this->fixedPeriod(), countryId: null);
    }
}
