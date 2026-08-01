<?php

declare(strict_types=1);

namespace App\Dashboard\Services;

use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Dashboard\DTOs\DashboardChart;
use App\Dashboard\DTOs\DashboardContext;
use App\Dashboard\DTOs\DashboardData;
use App\Dashboard\DTOs\DashboardFreshness;
use App\Dashboard\DTOs\DomainSummary;
use App\Dashboard\DTOs\KpiCard;
use App\Dashboard\DTOs\MoneyByCurrency;
use App\Dashboard\DTOs\ReportLink;
use App\Dashboard\DTOs\SummaryMetric;
use App\Dashboard\DTOs\SystemHealthData;
use App\Dashboard\Support\DashboardUrl;
use App\Filament\Pages\ReviewsQualityDashboard;
use App\Lessons\Enums\LessonOutcome;
use App\Models\User;
use App\Reporting\Contracts\BookingLessonMeetingOperationsReportServiceInterface;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\InstructorPerformanceReportServiceInterface;
use App\Reporting\Contracts\LearningAnalyticsReportServiceInterface;
use App\Reporting\Contracts\MarketplaceExecutiveReportServiceInterface;
use App\Reporting\Contracts\ReferralCommunicationReportServiceInterface;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\DTOs\Communication\ReviewQualityRatesData;
use App\Reporting\DTOs\Engagement\DemoConversionData;
use App\Reporting\DTOs\Engagement\StudentEngagementSummaryData;
use App\Reporting\DTOs\Finance\InstructorFinancialSummaryData;
use App\Reporting\DTOs\Finance\PaymentFinancialSummaryData;
use App\Reporting\DTOs\Finance\WalletFinancialSummaryData;
use App\Reporting\DTOs\Learning\HomeworkAnalyticsData;
use App\Reporting\DTOs\Learning\LearningPlanAnalyticsData;
use App\Reporting\DTOs\Learning\LearningTrendsData;
use App\Reporting\DTOs\Learning\MilestoneReviewAnalyticsData;
use App\Reporting\DTOs\Marketplace\MarketplaceComparisonData;
use App\Reporting\DTOs\Marketplace\MarketplaceDemandData;
use App\Reporting\DTOs\Marketplace\MarketplaceSupplyData;
use App\Reporting\DTOs\Operations\BookingOperationsSummaryData;
use App\Reporting\DTOs\Operations\LessonOutcomeSummaryData;
use App\Reporting\DTOs\ReportDefinition;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Enums\ReportingBookingType;
use App\Reporting\Enums\ReportingPeriodPreset;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Assembles the period-scoped dashboard.
 *
 * This class is NOT a calculation owner. Every figure it returns was
 * produced by an `App\Reporting` service (or `BookingAnalyticsService`,
 * the owner the `MetricRegistry` names for booking trend and
 * demo-to-paid conversion) and is only re-shaped for display here. It
 * introduces no dashboard-specific variant of an existing metric, and
 * it never invents a metric the registry does not define.
 *
 * Permission handling follows the nullable-section contract of
 * `App\Reporting\DTOs\Marketplace\ExecutiveKpiOverviewData`: each
 * section is built only after its permission is confirmed, so a
 * restricted section's queries never execute and the view has nothing
 * to hide. There are no "Restricted" placeholders.
 *
 * Each owning service is called at most once per composition; several
 * cards read different fields of the same returned DTO rather than
 * re-querying.
 */
final readonly class DashboardCompositionService
{
    /**
     * Period-scoped aggregation tolerates modest staleness, so this
     * mirrors `BookingAnalyticsService`'s existing 300-second policy.
     * The Needs Attention feed deliberately uses a far shorter TTL.
     */
    public const int CACHE_TTL_SECONDS = 300;

    /**
     * Bumped whenever the cached payload's shape changes. A payload
     * written by a different build is discarded rather than hydrated,
     * so a deploy can never serve a structurally stale entry.
     */
    private const string PAYLOAD_VERSION = 'v1';

    public const int MAX_PRIMARY_KPIS = 6;

    public const int MAX_PRIMARY_CHARTS = 4;

    public const int MAX_PRIMARY_REPORTS = 6;

    /** Subjects shown in the supply/demand chart — the full list belongs to the report. */
    private const int SUBJECT_CHART_LIMIT = 5;

    public function __construct(
        private DashboardPermissions $permissions,
        private BookingLessonMeetingOperationsReportServiceInterface $operations,
        private StudentEngagementReportServiceInterface $students,
        private InstructorPerformanceReportServiceInterface $instructors,
        private MarketplaceExecutiveReportServiceInterface $marketplace,
        private LearningAnalyticsReportServiceInterface $learning,
        private ReferralCommunicationReportServiceInterface $referralCommunication,
        private FinancialReportsServiceInterface $financial,
        private BookingAnalyticsServiceInterface $bookingAnalytics,
        private ProviderActivationReader $providers,
        private SystemHealthReader $systemHealth,
        private CacheRepository $cache,
    ) {}

    /**
     * Composes the dashboard, reading a cached result where one is warm.
     *
     * ONLY PRIMITIVES ARE CACHED. Storing the DTOs themselves would
     * serialize class instances into the cache store — and this
     * application's store is `database`, which survives deploys. A
     * payload written before a DTO's constructor changed then rehydrates
     * as `__PHP_Incomplete_Class` and keeps being served until the TTL
     * expires, producing a hard TypeError on a page that has no way to
     * recover. Round-tripping plain scalars makes a stale entry
     * harmless: at worst it carries slightly old numbers for the rest of
     * its TTL.
     *
     * `hydrate()` is additionally defensive about a payload whose shape
     * predates a change to the section list, for the same reason.
     */
    public function compose(User $user, DashboardContext $context): DashboardData
    {
        $key = $this->cacheKey($user, $context);

        // Destination-page gates read `auth()->user()`, so they can only
        // be evaluated for the authenticated user. Composing for someone
        // else (a console command, a queued job) would omit every
        // gated link — and caching THAT would serve a stripped
        // launchpad to the real user for the rest of the TTL. So a
        // composition that cannot evaluate those gates is computed fresh
        // and never written to the cache.
        if (! $this->canEvaluatePageGates($user)) {
            return $this->hydrate($context, $this->freshPayload($user, $context));
        }

        /** @var array<string, mixed> $payload */
        $payload = $this->cache->remember(
            $key,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->freshPayload($user, $context),
        );

        try {
            return $this->hydrate($context, $payload);
        } catch (Throwable $exception) {
            // A payload this build cannot read is discarded and rebuilt
            // once, rather than surfacing as a 500.
            Log::warning('Discarding an unreadable cached dashboard payload; recomposing.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->cache->forget($key);
            $payload = $this->freshPayload($user, $context);
            $this->cache->put($key, $payload, self::CACHE_TTL_SECONDS);

            return $this->hydrate($context, $payload);
        }
    }

    /** @return array<string, mixed> */
    private function freshPayload(User $user, DashboardContext $context): array
    {
        $sections = $this->buildSections($user, $context);

        return [
            'version' => self::PAYLOAD_VERSION,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'kpis' => array_map(static fn (KpiCard $c): array => $c->toArray(), $sections['kpis']),
            'charts' => array_map(static fn (DashboardChart $c): array => $c->toArray(), $sections['charts']),
            'summaries' => array_map(static fn (DomainSummary $s): array => $s->toArray(), $sections['summaries']),
            'primary_reports' => array_map(static fn (ReportLink $r): array => $r->toArray(), $sections['primary_reports']),
            'additional_reports' => array_map(static fn (ReportLink $r): array => $r->toArray(), $sections['additional_reports']),
            'administration_links' => $sections['administration_links'],
            'system_health' => $sections['system_health']?->toArray(),
            'reporting_hub_url' => $sections['reporting_hub_url'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function hydrate(DashboardContext $context, array $payload): DashboardData
    {
        if (($payload['version'] ?? null) !== self::PAYLOAD_VERSION) {
            throw new \RuntimeException('Cached dashboard payload was written by a different build.');
        }

        return new DashboardData(
            context: $context,
            freshness: new DashboardFreshness(
                // Cached, and says so. `ReportDataFreshness`'s own contract
                // forbids claiming Live while reading cached data.
                freshness: ReportDataFreshness::CachedWithTimestamp,
                generatedAt: CarbonImmutable::parse($payload['generated_at']),
                reportingTimezone: $context->timezone(),
                periodLabel: $context->period->label,
                ttlSeconds: self::CACHE_TTL_SECONDS,
            ),
            kpis: array_map(KpiCard::fromArray(...), $payload['kpis']),
            charts: array_map(DashboardChart::fromArray(...), $payload['charts']),
            summaries: array_map(DomainSummary::fromArray(...), $payload['summaries']),
            primaryReports: array_map(ReportLink::fromArray(...), $payload['primary_reports']),
            additionalReports: array_map(ReportLink::fromArray(...), $payload['additional_reports']),
            administrationLinks: $payload['administration_links'],
            systemHealth: $payload['system_health'] === null
                ? null
                : SystemHealthData::fromArray($payload['system_health']),
            reportingHubUrl: $payload['reporting_hub_url'],
        );
    }

    /** Discards this user's composed dashboard for every period. */
    public function forget(User $user, DashboardContext $context): void
    {
        $this->cache->forget($this->cacheKey($user, $context));
    }

    /**
     * Permission signature + resolved period + country. Two users with
     * different access can never collide, and granting a permission
     * changes the signature so stale output is not served.
     */
    private function cacheKey(User $user, DashboardContext $context): string
    {
        return sprintf(
            'dashboard:composition:%s:%s:%s',
            $user->getKey(),
            $this->permissions->signature($user),
            substr(hash('sha256', $context->cacheFragment()), 0, 24),
        );
    }

    /** @return array<string, mixed> */
    private function buildSections(User $user, DashboardContext $context): array
    {
        // Each owning service is consulted at most once; the resulting
        // DTOs are then read by however many cards need them.
        //
        // Every fetch goes through section(), which contains a failure to
        // the section that caused it: one report service throwing must
        // not take down the whole dashboard, and least of all the Needs
        // Attention section an operator relies on during an incident. A
        // failed section degrades to null — exactly the same shape as
        // "not permitted" — so the grid closes the gap either way.
        $bookings = $this->section('bookings', $this->permissions->canViewOperationsSummaries($user), fn () => $this->operations->bookingSummary($user, $context->period, $context->filters()));

        $lessons = $this->section('lessons', $this->permissions->canViewOperationsSummaries($user), fn () => $this->operations->lessonOutcomeSummary($user, $context->period, $context->filters()));

        $studentSummary = $this->section('studentSummary', $this->permissions->canViewStudents($user), fn () => $this->students->summary($user, $context->period, $context->filters()));

        $supply = $this->section('supply', $this->permissions->canViewMarketplace($user), fn () => $this->marketplace->marketplaceSupply($user, $context->period, $context->filters()));

        $demand = $this->section('demand', $this->permissions->canViewMarketplace($user), fn () => $this->marketplace->marketplaceDemand($user, $context->period, $context->filters()));

        $comparison = $this->section('comparison', $this->permissions->canViewMarketplace($user), fn () => $this->marketplace->marketplaceComparison($user, $context->period, $context->filters()));

        $conversion = $this->section('conversion', $this->permissions->canViewInstructors($user), fn () => $this->instructors->demoConversion($user, $context->period));

        $homework = $this->section('homework', $this->permissions->canViewLearning($user), fn () => $this->learning->homeworkSummary($user, $context->period, $context->filters()));

        $plans = $this->section('plans', $this->permissions->canViewLearning($user), fn () => $this->learning->planSummary($user, $context->period, $context->filters()));

        $milestones = $this->section('milestones', $this->permissions->canViewLearning($user), fn () => $this->learning->milestoneReviewSummary($user, $context->period, $context->filters()));

        $learningTrends = $this->section('learningTrends', $this->permissions->canViewLearning($user), fn () => $this->learning->trends($user, $context->period, $context->filters()));

        $qualityRates = $this->section('qualityRates', $this->permissions->canViewReviewQuality($user), fn () => $this->referralCommunication->reviewQualityRates($user, $context->period, $context->filters()));

        $wallet = $this->section('wallet', $this->permissions->canViewWallet($user), fn () => $this->financial->walletSummary($user, $context->period, $context->filters()));

        $payments = $this->section('payments', $this->permissions->canViewPayments($user), fn () => $this->financial->paymentSummary($user, $context->period, $context->filters()));

        $compensation = $this->section('compensation', $this->permissions->canViewInstructorCompensation($user), fn () => $this->financial->instructorFinancialSummary($user, $context->period, $context->filters()));

        $registrationTrend = $this->section('registrationTrend', $this->permissions->canViewStudents($user), fn () => $this->students->registrationTrend($user, $context->period, $context->filters()));

        return [
            'kpis' => $this->buildKpis($context, $bookings, $lessons, $studentSummary, $supply, $conversion, $registrationTrend),
            'charts' => $this->buildCharts($user, $context, $lessons, $registrationTrend, $supply, $demand, $learningTrends),
            'summaries' => $this->buildSummaries($user, $context, $supply, $comparison, $homework, $plans, $milestones, $qualityRates, $wallet, $payments, $compensation),
            'primary_reports' => $this->primaryReports($user, $context),
            'additional_reports' => $this->additionalReports($user, $context),
            'administration_links' => $this->administrationLinks($user),
            'system_health' => $this->permissions->canViewAnySystemHealth($user)
                ? $this->systemHealth->read($user)
                : null,
            'reporting_hub_url' => $this->permissions->availableReports($user) !== []
                ? DashboardUrl::reportingHub()
                : null,
        ];
    }

    /**
     * Fetches one section's data, or returns null when the viewer is not
     * permitted — the permission is evaluated BEFORE the closure runs,
     * so an unauthorised section issues no query at all.
     *
     * A throwing section is logged and degraded to null rather than
     * propagated. `AuthorizationException` is caught deliberately too:
     * a report service's own gate is the last word, and if this class's
     * permission map ever drifts from it, the correct outcome is a
     * missing section, never a 500.
     *
     * @template T
     *
     * @param  callable(): T  $fetch
     * @return T|null
     */
    private function section(string $key, bool $permitted, callable $fetch): mixed
    {
        if (! $permitted) {
            return null;
        }

        try {
            return $fetch();
        } catch (Throwable $exception) {
            Log::warning('Dashboard section failed to compose; it will be omitted.', [
                'section' => $key,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    // ── Primary KPIs ─────────────────────────────────────────────────

    /**
     * At most six. If a permission is missing the KPI is omitted and the
     * next permitted one takes its place, so the row never renders a gap
     * or a placeholder.
     *
     * No comparison-vs-previous-period figure is shown anywhere: no
     * authoritative previous-period mechanism exists
     * (`ReportingPeriod` offers only `forPreset()`/`custom()`), and a
     * synthesised delta would be a fabricated metric.
     *
     * @param  array<string, int>|null  $registrationTrend
     * @return list<KpiCard>
     */
    private function buildKpis(
        DashboardContext $context,
        ?BookingOperationsSummaryData $bookings,
        ?LessonOutcomeSummaryData $lessons,
        ?StudentEngagementSummaryData $studentSummary,
        ?MarketplaceSupplyData $supply,
        ?DemoConversionData $conversion,
        ?array $registrationTrend,
    ): array {
        $periodLabel = $context->periodLabel();
        $cards = [];

        if ($bookings !== null) {
            $cards[] = new KpiCard(
                key: 'bookings_scheduled',
                label: 'Bookings scheduled',
                value: number_format($bookings->total),
                contextLabel: $periodLabel,
                definition: 'Every booking created in the period, regardless of eventual outcome.',
                icon: 'heroicon-o-calendar-days',
                url: DashboardUrl::operations($context),
            );
        }

        if ($lessons !== null) {
            $completed = (int) ($lessons->byOutcome[LessonOutcome::Completed->value] ?? 0);

            $cards[] = new KpiCard(
                key: 'lessons_completed',
                label: 'Lessons completed',
                value: number_format($completed),
                contextLabel: $periodLabel,
                // The distinction that matters: the finalized outcome, not
                // the lesson status, which can precede finalization.
                definition: 'Lessons whose finalized outcome is Completed — never LessonStatus::Completed alone.',
                icon: 'heroicon-o-check-badge',
                url: DashboardUrl::operations($context, [
                    'lesson_outcome' => LessonOutcome::Completed->value,
                ]),
            );
        }

        if ($studentSummary !== null) {
            $cards[] = new KpiCard(
                key: 'engaged_students',
                label: 'Engaged students',
                value: number_format($studentSummary->engagedInPeriod),
                contextLabel: $periodLabel,
                // Deliberately not "active students": account status and
                // engagement are different concepts in this platform.
                definition: 'Students with at least one booking created, or one lesson finalized Completed, in the period. Not an account status.',
                icon: 'heroicon-o-user-group',
                url: DashboardUrl::studentEngagement($context),
            );

            $cards[] = new KpiCard(
                key: 'new_students',
                label: 'New students',
                value: number_format($studentSummary->newInPeriod),
                contextLabel: $periodLabel,
                definition: 'Student-role accounts created in the period, in the reporting timezone.',
                icon: 'heroicon-o-user-plus',
                url: DashboardUrl::studentEngagement($context),
                // The only authoritative daily series available for a KPI
                // sparkline. Never synthesised.
                sparkline: $registrationTrend === null ? null : array_values($registrationTrend),
            );
        }

        if ($supply !== null) {
            $cards[] = new KpiCard(
                key: 'active_instructors',
                label: 'Active instructors',
                value: number_format($supply->activeInstructors),
                contextLabel: 'As of now',
                definition: 'Instructors currently in the Active lifecycle status. A current-state figure — the period selector does not change it.',
                icon: 'heroicon-o-academic-cap',
                url: DashboardUrl::marketplace($context),
            );
        }

        if ($conversion !== null) {
            $rate = $conversion->conversionRate;

            $cards[] = new KpiCard(
                key: 'demo_to_paid_conversion',
                label: 'Demo-to-paid conversion',
                // Null means no demo bookers — a missing denominator, not
                // a zero rate. Rendering 0% here would be a factual error.
                value: $rate === null ? '—' : number_format($rate, 1).'%',
                contextLabel: $periodLabel,
                definition: 'Distinct demo bookers in the period who later created any paid booking. Per-instructor and per-subject conversion is unavailable by definition.',
                icon: 'heroicon-o-arrow-trending-up',
                url: DashboardUrl::instructorPerformance($context),
                isUnavailable: $rate === null,
                unavailableReason: $rate === null ? 'No demo bookings in this period, so there is no denominator.' : null,
            );
        }

        return array_slice($cards, 0, self::MAX_PRIMARY_KPIS);
    }

    // ── Charts ───────────────────────────────────────────────────────

    /**
     * @param  array<string, int>|null  $registrationTrend
     * @return list<DashboardChart>
     */
    private function buildCharts(
        User $user,
        DashboardContext $context,
        ?LessonOutcomeSummaryData $lessons,
        ?array $registrationTrend,
        ?MarketplaceSupplyData $supply,
        ?MarketplaceDemandData $demand,
        ?LearningTrendsData $learningTrends,
    ): array {
        $charts = array_filter([
            $this->lessonOutcomeChart($context, $lessons),
            $this->registrationChart($context, $registrationTrend),
            $this->bookingsPerDayChart($user, $context),
            $this->supplyDemandChart($context, $supply, $demand),
            $this->homeworkChart($context, $learningTrends),
        ]);

        return array_slice(array_values($charts), 0, self::MAX_PRIMARY_CHARTS + 1);
    }

    /** Chart A — 100% stacked composition of finalized lesson outcomes, fixed semantic order. */
    private function lessonOutcomeChart(DashboardContext $context, ?LessonOutcomeSummaryData $lessons): ?DashboardChart
    {
        if ($lessons === null) {
            return null;
        }

        // Fixed best-to-worst order, never sorted by size, so drift is
        // visible across page loads.
        $order = [
            LessonOutcome::Completed,
            LessonOutcome::StudentNoShow,
            LessonOutcome::InstructorNoShow,
            LessonOutcome::BothAbsent,
            LessonOutcome::TechnicalIssue,
            LessonOutcome::Cancelled,
        ];

        $colors = [
            LessonOutcome::Completed->value => '#16a34a',
            LessonOutcome::StudentNoShow->value => '#f59e0b',
            LessonOutcome::InstructorNoShow->value => '#ef4444',
            LessonOutcome::BothAbsent->value => '#a855f7',
            LessonOutcome::TechnicalIssue->value => '#0ea5e9',
            LessonOutcome::Cancelled->value => '#64748b',
        ];

        $total = 0;
        foreach ($order as $outcome) {
            $total += (int) ($lessons->byOutcome[$outcome->value] ?? 0);
        }

        $segments = [];
        $datasets = [];

        foreach ($order as $outcome) {
            $value = (int) ($lessons->byOutcome[$outcome->value] ?? 0);

            $segments[] = [
                'label' => $outcome->label(),
                'value' => $value,
                'percentage' => $total > 0 ? round($value / $total * 100, 1) : null,
                'color' => $colors[$outcome->value],
                'url' => DashboardUrl::operations($context, ['lesson_outcome' => $outcome->value]),
            ];

            $datasets[] = [
                'label' => $outcome->label(),
                'data' => [$value],
                'color' => $colors[$outcome->value],
            ];
        }

        return new DashboardChart(
            key: 'lesson_outcomes',
            title: 'Lesson outcomes',
            subtitle: 'Finalized outcomes in the selected period',
            labels: ['Outcomes'],
            datasets: $datasets,
            segments: $segments,
            url: DashboardUrl::operations($context),
            emptyMessage: 'No lessons were finalized in this period.',
        );
    }

    /** Chart B — the authoritative gap-filled daily registration series. */
    private function registrationChart(DashboardContext $context, ?array $registrationTrend): ?DashboardChart
    {
        if ($registrationTrend === null) {
            return null;
        }

        return new DashboardChart(
            key: 'student_registrations',
            title: 'New student registrations',
            subtitle: 'Daily, in the reporting timezone',
            labels: array_keys($registrationTrend),
            datasets: [[
                'label' => 'New students',
                'data' => array_values($registrationTrend),
                'color' => '#ec4899',
            ]],
            url: DashboardUrl::studentEngagement($context),
            emptyMessage: 'No student registrations in this period.',
        );
    }

    /**
     * Chart C — bookings per day, from `BookingAnalyticsService::trend()`,
     * the existing owner (already cached for 300s).
     *
     * That owner returns one gap-filled series with no booking-type
     * split and no country dimension, so this chart is single-series and
     * is explicitly labelled global rather than pretending the country
     * filter applied to it.
     */
    private function bookingsPerDayChart(User $user, DashboardContext $context): ?DashboardChart
    {
        // The only owning service consulted outside buildSections(), so it
        // is wrapped here to keep the same containment guarantee.
        $trend = $this->section(
            'bookingsPerDay',
            $this->permissions->canViewBookingLessonKpis($user),
            fn (): Collection => $this->bookingAnalytics->trend(
                $context->period->startUtc,
                $context->period->endUtcExclusive->subSecond(),
            ),
        );

        if ($trend === null) {
            return null;
        }

        $labels = $trend->pluck('day')->all();
        $values = $trend->pluck('bookings')->map(static fn (mixed $v): int => (int) $v)->all();

        return new DashboardChart(
            key: 'bookings_per_day',
            title: 'Bookings per day',
            subtitle: $context->countryId !== null
                ? 'Daily — platform-wide, not filtered by country'
                : 'Daily, all booking types',
            labels: $labels,
            datasets: [[
                'label' => 'Bookings created',
                'data' => $values,
                'color' => '#6366f1',
            ]],
            url: DashboardUrl::operations($context, ['booking_type' => ReportingBookingType::PaidOneToOne->value]),
            emptyMessage: 'No bookings were created in this period.',
        );
    }

    /**
     * Chart D — instructor supply beside booking demand for the same
     * five subjects. Deliberately not called utilization, and no
     * ranking or composite gap score is invented: the marketplace
     * service compares on compatible dimensions only.
     */
    private function supplyDemandChart(DashboardContext $context, ?MarketplaceSupplyData $supply, ?MarketplaceDemandData $demand): ?DashboardChart
    {
        if ($supply === null || $demand === null) {
            return null;
        }

        $demandBySubject = [];
        foreach ($demand->bySubject as $row) {
            $demandBySubject[$row->label] = (int) $row->count;
        }

        $subjects = [];
        foreach ($supply->bySubject as $row) {
            $subjects[$row->label] = (int) $row->count;
        }

        // Union of both sides, ordered by total activity, capped at five.
        $labels = array_unique([...array_keys($subjects), ...array_keys($demandBySubject)]);
        usort($labels, static fn (string $a, string $b): int => (($subjects[$b] ?? 0) + ($demandBySubject[$b] ?? 0))
            <=> (($subjects[$a] ?? 0) + ($demandBySubject[$a] ?? 0)));
        $labels = array_slice($labels, 0, self::SUBJECT_CHART_LIMIT);

        return new DashboardChart(
            key: 'supply_demand',
            title: 'Subject supply and demand',
            subtitle: sprintf('Top %d subjects by combined activity', self::SUBJECT_CHART_LIMIT),
            labels: array_values($labels),
            datasets: [
                [
                    'label' => 'Active instructors',
                    'data' => array_map(static fn (string $l): int => $subjects[$l] ?? 0, $labels),
                    'color' => '#0ea5e9',
                ],
                [
                    'label' => 'Bookings in period',
                    'data' => array_map(static fn (string $l): int => $demandBySubject[$l] ?? 0, $labels),
                    'color' => '#f97316',
                ],
            ],
            url: DashboardUrl::marketplace($context),
            emptyMessage: 'No instructor supply or booking demand recorded yet.',
        );
    }

    /**
     * Chart E — homework assigned against submitted. Rendered in the
     * learning section rather than the primary chart row, and only for a
     * viewer with `ViewLearningReports`.
     */
    private function homeworkChart(DashboardContext $context, ?LearningTrendsData $learningTrends): ?DashboardChart
    {
        if ($learningTrends === null) {
            return null;
        }

        $labels = array_keys($learningTrends->homeworkAssigned);

        return new DashboardChart(
            key: 'homework_activity',
            title: 'Homework assigned and submitted',
            subtitle: 'Daily — the gap between the lines is unsubmitted work',
            labels: $labels,
            datasets: [
                [
                    'label' => 'Assigned',
                    'data' => array_values($learningTrends->homeworkAssigned),
                    'color' => '#8b5cf6',
                ],
                [
                    'label' => 'Submitted',
                    'data' => array_values($learningTrends->homeworkSubmitted),
                    'color' => '#22c55e',
                ],
            ],
            url: DashboardUrl::learningAnalytics($context, section: 'homework'),
            emptyMessage: 'No homework activity in this period.',
        );
    }

    // ── Domain summaries ─────────────────────────────────────────────

    /** @return list<DomainSummary> */
    private function buildSummaries(
        User $user,
        DashboardContext $context,
        ?MarketplaceSupplyData $supply,
        ?MarketplaceComparisonData $comparison,
        ?HomeworkAnalyticsData $homework,
        ?LearningPlanAnalyticsData $plans,
        ?MilestoneReviewAnalyticsData $milestones,
        ?ReviewQualityRatesData $qualityRates,
        ?WalletFinancialSummaryData $wallet,
        ?PaymentFinancialSummaryData $payments,
        ?InstructorFinancialSummaryData $compensation,
    ): array {
        return array_values(array_filter([
            $this->marketplaceSummary($context, $supply, $comparison),
            $this->learningSummary($context, $homework, $plans, $milestones),
            $this->qualitySummary($user, $context, $qualityRates),
            $this->moneySummary($user, $context, $wallet, $payments, $compensation),
        ]));
    }

    private function marketplaceSummary(DashboardContext $context, ?MarketplaceSupplyData $supply, ?MarketplaceComparisonData $comparison): ?DomainSummary
    {
        if ($supply === null) {
            return null;
        }

        $metrics = [
            new SummaryMetric(
                label: 'Active instructors',
                value: number_format($supply->activeInstructors),
                hint: 'Current lifecycle status',
            ),
            new SummaryMetric(
                label: 'Active without published availability',
                value: number_format($supply->activeWithoutPublishedAvailability),
                hint: 'Active but not bookable',
            ),
        ];

        if ($comparison !== null) {
            $metrics[] = $comparison->demandPerActiveInstructor === null
                ? SummaryMetric::unavailable('Demand per active instructor', 'No active instructors, so there is no denominator.')
                : new SummaryMetric(
                    label: 'Demand per active instructor',
                    value: number_format($comparison->demandPerActiveInstructor, 2),
                    hint: 'Bookings in period ÷ active instructors',
                );
        }

        return new DomainSummary(
            key: 'marketplace',
            title: 'Marketplace',
            icon: 'heroicon-o-globe-alt',
            metrics: array_slice($metrics, 0, 3),
            reportLabel: 'Open Marketplace Supply & Demand',
            reportUrl: DashboardUrl::marketplace($context),
        );
    }

    private function learningSummary(DashboardContext $context, ?HomeworkAnalyticsData $homework, ?LearningPlanAnalyticsData $plans, ?MilestoneReviewAnalyticsData $milestones): ?DomainSummary
    {
        if ($homework === null) {
            return null;
        }

        $metrics = [
            new SummaryMetric(
                label: 'Homework overdue',
                value: number_format($homework->currentlyOverdue),
                hint: 'As of now',
            ),
            $homework->onTimeSubmissionRate === null
                ? SummaryMetric::unavailable('On-time submission rate', 'No homework reached its due date in this period.')
                : new SummaryMetric(
                    label: 'On-time submission rate',
                    value: number_format($homework->onTimeSubmissionRate, 1).'%',
                    hint: $context->periodLabel(),
                ),
        ];

        if ($milestones !== null) {
            $metrics[] = new SummaryMetric(
                label: 'Plans review-due',
                value: number_format($milestones->plansCurrentlyReviewDue),
                hint: 'As of now',
            );
        } elseif ($plans !== null) {
            $metrics[] = new SummaryMetric(
                label: 'Plans created',
                value: number_format($plans->createdInPeriod),
                hint: $context->periodLabel(),
            );
        }

        return new DomainSummary(
            key: 'learning',
            title: 'Learning',
            icon: 'heroicon-o-book-open',
            metrics: array_slice($metrics, 0, 3),
            reportLabel: 'Open Learning Analytics',
            reportUrl: DashboardUrl::learningAnalytics($context),
        );
    }

    private function qualitySummary(User $user, DashboardContext $context, ?ReviewQualityRatesData $qualityRates): ?DomainSummary
    {
        if ($qualityRates === null) {
            return null;
        }

        $metrics = [
            $qualityRates->platformAverageRating === null
                ? SummaryMetric::unavailable('Platform average rating', 'No ratings yet.')
                : new SummaryMetric(
                    label: 'Platform average rating',
                    value: number_format($qualityRates->platformAverageRating, 2),
                    hint: sprintf('%s eligible published reviews', number_format($qualityRates->publishedEligibleReviewCount)),
                ),
            $qualityRates->submissionRate === null
                ? SummaryMetric::unavailable('Review submission rate', 'No review windows concluded in this period.')
                : new SummaryMetric(
                    label: 'Review submission rate',
                    value: number_format($qualityRates->submissionRate, 1).'%',
                    hint: 'Concluded review windows that were used',
                ),
            new SummaryMetric(
                label: 'Open quality alerts',
                value: number_format($qualityRates->openQualityAlerts),
                hint: 'As of now',
            ),
        ];

        // These figures are produced by `reviewQualityRates()`, whose
        // owning page is Referrals & Communications — not the
        // moderation dashboard. The moderation dashboard is offered only
        // when its own, stricter `ViewQualityDashboard` gate would admit
        // the viewer; otherwise the link goes to the page that actually
        // owns the numbers shown here, so it can never 403.
        $moderationAdmits = $this->pageAdmits($user, ReviewsQualityDashboard::class);

        return new DomainSummary(
            key: 'quality',
            title: 'Quality & trust',
            icon: 'heroicon-o-shield-check',
            metrics: $metrics,
            reportLabel: $moderationAdmits ? 'Open Reviews & Quality' : 'Open Review & Quality Analytics',
            reportUrl: $moderationAdmits
                ? DashboardUrl::reviewsQuality()
                : DashboardUrl::referralCommunication($context, section: 'quality'),
        );
    }

    /**
     * Money is per-currency throughout. No figure here is labelled
     * revenue, and booking value, external collections and wallet
     * consumption are never added together.
     *
     * When no collection provider is activated, the notice says so
     * rather than letting an empty collections figure read as evidence
     * of zero business.
     */
    private function moneySummary(User $user, DashboardContext $context, ?WalletFinancialSummaryData $wallet, ?PaymentFinancialSummaryData $payments, ?InstructorFinancialSummaryData $compensation): ?DomainSummary
    {
        if ($wallet === null && $payments === null && $compensation === null) {
            return null;
        }

        $metrics = [];

        if ($wallet !== null) {
            $liability = MoneyByCurrency::fromMap($wallet->currentLiabilityByCurrency, limit: 2);

            $metrics[] = new SummaryMetric(
                label: 'Wallet liability',
                value: $liability === []
                    ? '—'
                    : implode(' · ', array_map(static fn (MoneyByCurrency $m): string => $m->formatted, $liability)),
                // Explicitly as-of, never period-scoped: the selector
                // does not change this figure.
                hint: 'As of '.$wallet->liabilityAsOf,
            );
        }

        if ($payments !== null) {
            $metrics[] = $payments->successRate === null
                ? SummaryMetric::unavailable('Payment success rate', 'No terminal payment attempts in this period.')
                : new SummaryMetric(
                    label: 'Payment success rate',
                    value: number_format($payments->successRate, 1).'%',
                    hint: $context->periodLabel(),
                );
        }

        if ($compensation !== null) {
            $releasable = $this->releasableLiability($compensation->earningLiabilityByStatusCurrency);

            $metrics[] = new SummaryMetric(
                label: 'Instructor earning liability (releasable)',
                value: $releasable === []
                    ? '—'
                    : implode(' · ', array_map(static fn (MoneyByCurrency $m): string => $m->formatted, $releasable)),
                hint: 'As of now',
            );
        }

        return new DomainSummary(
            key: 'money',
            title: 'Money position',
            icon: 'heroicon-o-currency-rupee',
            metrics: array_slice($metrics, 0, 3),
            reportLabel: $payments !== null ? 'Open Payments & Reconciliation' : 'Open Wallet & Refunds',
            reportUrl: $payments !== null
                ? DashboardUrl::payments($context)
                : DashboardUrl::walletRefunds($context),
            notice: $this->providers->collectionNotice($user),
        );
    }

    /**
     * @param  array<string, array<string, int>>  $byStatusCurrency
     * @return list<MoneyByCurrency>
     */
    private function releasableLiability(array $byStatusCurrency): array
    {
        $releasable = $byStatusCurrency['releasable'] ?? [];

        return MoneyByCurrency::fromMap(is_array($releasable) ? $releasable : [], limit: 2);
    }

    // ── Report launchpad ─────────────────────────────────────────────

    /**
     * Driven entirely by `ReportRegistryInterface::availableFor($user)`
     * — the same mechanism `ReportingHub::categories()` uses — so a
     * newly registered report appears here automatically, and an
     * unavailable or unpermitted one can never appear at all.
     *
     * @return list<ReportLink>
     */
    private function primaryReports(User $user, DashboardContext $context): array
    {
        return array_slice($this->reportLinks($user, $context), 0, self::MAX_PRIMARY_REPORTS);
    }

    /** @return list<ReportLink> */
    private function additionalReports(User $user, DashboardContext $context): array
    {
        return array_slice($this->reportLinks($user, $context), self::MAX_PRIMARY_REPORTS);
    }

    /**
     * Preferred order for the first six links. Finance entries rank
     * above lower-priority operational ones only for a viewer who
     * actually holds the finance permission — which the registry has
     * already enforced by the time a definition reaches this list.
     *
     * @return list<ReportLink>
     */
    private function reportLinks(User $user, DashboardContext $context): array
    {
        $preferred = [
            'booking_lesson_meeting_operations',
            'student_engagement',
            'instructor_performance',
            'marketplace_supply_demand',
            'learning_progress',
            'reviews_quality_dashboard',
            'finance_overview',
            'payment_outcomes',
            'earnings_settlements',
            'wallet_activity',
            'recharge_monitoring',
            'executive_summary',
        ];

        $available = [];
        foreach ($this->permissions->availableReports($user) as $definition) {
            // The registry's `requiredViewPermission` is not always the
            // gate the destination page enforces. Two report pages guard
            // themselves with a DIFFERENT check than their definition
            // declares — `ReviewsQualityDashboard` requires
            // `ViewQualityDashboard`, and `BookingReports` requires the
            // `Booking` viewAny policy — so a registry-available report
            // can still 403 on arrival. Consulting the page's own
            // `canAccess()` is what keeps every launchpad link openable.
            if (! $this->pageAdmits($user, $definition->routeName)) {
                continue;
            }

            $url = $this->reportUrl($definition, $context);

            if ($url !== null) {
                $available[$definition->key] = ReportLink::fromDefinition($definition, $url);
            }
        }

        $ordered = [];
        foreach ($preferred as $key) {
            if (isset($available[$key])) {
                $ordered[] = $available[$key];
                unset($available[$key]);
            }
        }

        return [...$ordered, ...array_values($available)];
    }

    /**
     * Whether the destination page would actually admit this viewer.
     *
     * Filament page gates read `auth()->user()` by design, so the
     * identity is asserted rather than assumed — a composition built
     * for some other user must never inherit the current session's
     * access. A page with no `canAccess()` is treated as admitting
     * (the panel-level gate still applies).
     *
     * @param  class-string|null  $pageClass
     */
    private function pageAdmits(User $user, ?string $pageClass): bool
    {
        if ($pageClass === null || ! class_exists($pageClass) || ! method_exists($pageClass, 'canAccess')) {
            return true;
        }

        if (! $this->canEvaluatePageGates($user)) {
            // Cannot evaluate the page's own gate for a user who is not
            // the authenticated one. The registry has already authorised
            // the report, so the link is kept and the page's gate remains
            // the final word on arrival — this never widens access, and
            // compose() refuses to cache such a composition anyway.
            return true;
        }

        return (bool) $pageClass::canAccess();
    }

    /**
     * Filament page gates read `auth()->user()`, so they are only
     * meaningful when the composed user IS the authenticated user.
     */
    private function canEvaluatePageGates(User $user): bool
    {
        return auth()->user()?->is($user) === true;
    }

    /**
     * Resolves a definition's own registered route class. Definitions
     * sharing a page (Learning Analytics hosts three) all resolve to
     * that page; the dashboard's own cards use
     * {@see DashboardUrl::learningAnalytics()} with a section instead.
     */
    private function reportUrl(ReportDefinition $definition, DashboardContext $context): ?string
    {
        if ($definition->routeName === null || ! class_exists($definition->routeName)) {
            return null;
        }

        // The dashboard period is forwarded only to a report that actually
        // binds one. Appending `?period=` to a current-state page such as
        // Reviews & Quality or Recharge Monitoring would advertise a
        // filter that page does not apply.
        $parameters = property_exists($definition->routeName, 'periodPreset')
            ? ['period' => $context->period->preset->value]
            : [];

        if ($parameters !== [] && $context->period->preset === ReportingPeriodPreset::Custom) {
            $parameters['start'] = $context->period->start->toDateString();
            $parameters['end'] = $context->period->end->subDay()->toDateString();
        }

        return $definition->routeName::getUrl($parameters);
    }

    // ── Secondary administration links ───────────────────────────────

    /**
     * Deliberately secondary. User creation, settings, security, the
     * activity log and login history are legitimate but are not daily
     * marketplace operations, so they sit below the business content.
     * Page authoring and role creation are not here at all — they
     * belong to their own sidebar modules.
     *
     * @return list<array{label: string, url: string, icon: string, description: string}>
     */
    private function administrationLinks(User $user): array
    {
        $candidates = [
            [
                'permission' => 'Create:User',
                'label' => 'Create user',
                'url' => fn (): string => route('filament.admin.resources.users.create'),
                'icon' => 'heroicon-o-user-plus',
                'description' => 'Add an administrator or staff account',
            ],
            [
                'permission' => 'ViewAny:User',
                'label' => 'Users',
                'url' => fn (): string => route('filament.admin.resources.users.index'),
                'icon' => 'heroicon-o-users',
                'description' => 'Browse all accounts',
            ],
            [
                'permission' => 'View:GeneralSettingsPage',
                'label' => 'Settings',
                'url' => fn (): string => route('filament.admin.pages.settings.general'),
                'icon' => 'heroicon-o-cog-6-tooth',
                'description' => 'Platform configuration',
            ],
            [
                'permission' => 'security.authentication.view',
                'label' => 'Security',
                'url' => fn (): string => route('filament.admin.pages.security.authentication'),
                'icon' => 'heroicon-o-lock-closed',
                'description' => 'Authentication and access control',
            ],
            [
                'permission' => 'ViewAny:Activity',
                'label' => 'Activity log',
                'url' => fn (): string => route('filament.admin.resources.activity-logs.index'),
                'icon' => 'heroicon-o-clipboard-document-list',
                'description' => 'Full audit trail',
            ],
            [
                'permission' => 'ViewAny:LoginHistory',
                'label' => 'Login history',
                'url' => fn (): string => route('filament.admin.resources.login-history.index'),
                'icon' => 'heroicon-o-finger-print',
                'description' => 'Session and device history',
            ],
        ];

        $links = [];

        foreach ($candidates as $candidate) {
            if (! $this->permissions->can($user, $candidate['permission'])) {
                continue;
            }

            $links[] = [
                'label' => $candidate['label'],
                'url' => ($candidate['url'])(),
                'icon' => $candidate['icon'],
                'description' => $candidate['description'],
            ];
        }

        return $links;
    }
}
