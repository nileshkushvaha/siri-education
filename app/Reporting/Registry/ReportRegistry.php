<?php

declare(strict_types=1);

namespace App\Reporting\Registry;

use App\Filament\Pages\BookingLessonMeetingOperations;
use App\Filament\Pages\BookingReports;
use App\Filament\Pages\ExecutiveKpiOverview;
use App\Filament\Pages\FinanceOverview;
use App\Filament\Pages\InstructorFinancials;
use App\Filament\Pages\InstructorPerformance;
use App\Filament\Pages\LearningAnalytics;
use App\Filament\Pages\MarketplaceSupplyDemand;
use App\Filament\Pages\PaymentsReconciliation;
use App\Filament\Pages\ReferralCommunicationReports;
use App\Filament\Pages\ReviewsQualityDashboard;
use App\Filament\Pages\StudentEngagement;
use App\Filament\Pages\WalletRefunds;
use App\Models\User;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\ReportDefinition;
use App\Reporting\Enums\ReportCategory;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Exceptions\DuplicateReportKeyException;
use App\Reporting\Filters\ReportFilterKey;
use App\Reporting\Support\UniqueDefinitionKeys;

/**
 * The Version 1 report catalogue (Phase 18B §10). Three entries
 * describe report pages that exist and work today: `BookingReports`
 * and `ReviewsQualityDashboard` (both predate Phase 18B) and
 * `BookingLessonMeetingOperations` (Phase 18C — real operational
 * queries, replacing the Phase 18B `operational_queue` placeholder
 * under the same key rename). The remaining entries are explicit
 * placeholders (`available: false`, no route) for every other approved
 * Version 1 category, so a later Phase 18 slice has stable,
 * agreed-upon metadata to implement against rather than inventing its
 * own report key/category/permission from scratch. No placeholder
 * exposes a route, and none is rendered with fabricated data — the
 * landing page (§18) renders them as "planned", not as a broken link.
 */
final class ReportRegistry implements ReportRegistryInterface
{
    /** @var array<string, ReportDefinition> */
    private array $definitions;

    public function __construct(private readonly ReportAccessContextInterface $access)
    {
        $this->definitions = $this->buildDefinitions();
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $key): ?ReportDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function availableFor(User $user): array
    {
        return array_values(array_filter(
            $this->definitions,
            fn (ReportDefinition $definition): bool => $this->access->canView($user, $definition),
        ));
    }

    /** @return array<string, ReportDefinition> */
    private function buildDefinitions(): array
    {
        return UniqueDefinitionKeys::index(
            $this->definitionList(),
            fn (ReportDefinition $definition): string => $definition->key,
            fn (string $key) => throw DuplicateReportKeyException::forKey($key),
        );
    }

    /** @return list<ReportDefinition> */
    private function definitionList(): array
    {
        return [
            new ReportDefinition(
                key: 'executive_summary',
                label: 'Executive KPI Overview',
                description: 'Cross-domain headline KPIs composed from the owning reports (Phase 18H) — every figure keeps its original owner, permission and definitions; no calculation happens here.',
                category: ReportCategory::Executive,
                requiredViewPermission: 'ViewExecutiveReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: true,
                supportedFilters: [],
                defaultPeriodPreset: ReportingPeriodPreset::ThisMonth,
                dataSourceDomain: 'Cross-domain composition (Phases 18C–18G report services)',
                freshness: ReportDataFreshness::Live,
                routeName: ExecutiveKpiOverview::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'booking_lesson_meeting_operations',
                label: 'Booking, Lesson & Meeting Operations',
                description: 'Actionable day-to-day operational items: today\'s lessons, cancellations, no-shows, technical issues, and meeting creation failures.',
                category: ReportCategory::Operations,
                requiredViewPermission: 'ViewOperationalReports',
                requiredExportPermission: 'ExportReports',
                sensitive: false,
                financial: false,
                supportedFilters: [
                    ReportFilterKey::Country, ReportFilterKey::Subject, ReportFilterKey::EducationLevel, ReportFilterKey::Instructor,
                    ReportFilterKey::BookingType, ReportFilterKey::BookingStatus, ReportFilterKey::LessonStatus,
                    ReportFilterKey::LessonOutcome, ReportFilterKey::MeetingStatus,
                ],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Booking, Lessons, Meetings',
                freshness: ReportDataFreshness::Live,
                routeName: BookingLessonMeetingOperations::class,
                exportAvailable: true,
                available: true,
            ),
            new ReportDefinition(
                key: 'student_engagement',
                label: 'Student Engagement',
                description: 'Student acquisition, verification, engagement, learning participation and inactivity review.',
                category: ReportCategory::Students,
                requiredViewPermission: 'ViewStudentReports',
                requiredExportPermission: 'ExportReports',
                sensitive: true,
                financial: false,
                supportedFilters: [ReportFilterKey::Country, ReportFilterKey::EducationLevel, ReportFilterKey::Student, ReportFilterKey::StudentStatus],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Users, Booking, Lessons, Learning Plans, Homework, Reviews',
                freshness: ReportDataFreshness::Live,
                routeName: StudentEngagement::class,
                exportAvailable: true,
                available: true,
            ),
            new ReportDefinition(
                key: 'instructor_performance',
                label: 'Instructor Performance',
                description: 'Instructor lifecycle, teaching activity, booked hours, ratings and demo-to-paid conversion.',
                category: ReportCategory::Instructors,
                requiredViewPermission: 'ViewInstructorReports',
                requiredExportPermission: 'ExportReports',
                sensitive: true,
                financial: false,
                supportedFilters: [ReportFilterKey::Country, ReportFilterKey::Instructor, ReportFilterKey::Subject, ReportFilterKey::InstructorStatus, ReportFilterKey::BookingType, ReportFilterKey::LessonOutcome],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Users, Booking, Lessons, Reviews (aggregates), Quality',
                freshness: ReportDataFreshness::Live,
                routeName: InstructorPerformance::class,
                exportAvailable: true,
                available: true,
            ),
            new ReportDefinition(
                key: 'booking_lesson_kpis',
                label: 'Booking Reports',
                description: 'Booking KPIs, popular subjects/time slots, and teacher utilization. Its legacy KPI CSV is registered through the shared export contract (Phase 18I) with unchanged columns and filename.',
                category: ReportCategory::BookingsLessons,
                requiredViewPermission: 'ViewBookingLessonReports',
                requiredExportPermission: 'ExportReports',
                sensitive: false,
                financial: false,
                supportedFilters: [ReportFilterKey::BookingType, ReportFilterKey::RecurrenceType, ReportFilterKey::BookingStatus, ReportFilterKey::Country, ReportFilterKey::Subject],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Booking',
                freshness: ReportDataFreshness::Live,
                routeName: BookingReports::class,
                exportAvailable: true,
                available: true,
            ),
            new ReportDefinition(
                key: 'meeting_reliability',
                label: 'Meeting Reliability',
                description: 'Formally unavailable (Phase 18J closure decision): Version 1 records meeting creation lifecycle only — no attendance callbacks, uptime or provider delivery outcomes exist, and reliability is never inferred from creation status alone. Creation outcomes are covered by the Operations report.',
                category: ReportCategory::Meetings,
                requiredViewPermission: 'ViewMeetingReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: false,
                supportedFilters: [ReportFilterKey::Instructor],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Booking (Meetings)',
                freshness: ReportDataFreshness::Live,
                routeName: null,
                exportAvailable: false,
                available: false,
            ),
            new ReportDefinition(
                key: 'learning_progress',
                label: 'Learning Analytics',
                description: 'Learning Dashboard: goals, plans, milestones, progress reviews and homework (Phase 18F).',
                category: ReportCategory::Learning,
                requiredViewPermission: 'ViewLearningReports',
                requiredExportPermission: 'ExportReports',
                sensitive: true,
                financial: false,
                supportedFilters: [
                    ReportFilterKey::Student, ReportFilterKey::Instructor, ReportFilterKey::Subject,
                    ReportFilterKey::EducationLevel, ReportFilterKey::LearningPlanStatus,
                    ReportFilterKey::LearningGoalStatus, ReportFilterKey::HomeworkStatus,
                ],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Learning Plans, Learning Goals, Milestones, Progress Reviews, Homework',
                freshness: ReportDataFreshness::Live,
                routeName: LearningAnalytics::class,
                exportAvailable: true,
                available: true,
            ),
            new ReportDefinition(
                key: 'learning_plan_report',
                label: 'Learning Plan Report',
                description: 'Learning Plan lifecycle, goals, milestones and progress reviews — a section of the Learning Analytics page.',
                category: ReportCategory::Learning,
                requiredViewPermission: 'ViewLearningReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: false,
                supportedFilters: [
                    ReportFilterKey::Student, ReportFilterKey::Instructor, ReportFilterKey::Subject,
                    ReportFilterKey::EducationLevel, ReportFilterKey::LearningPlanStatus,
                    ReportFilterKey::LearningGoalStatus,
                ],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Learning Plans, Learning Goals, Milestones, Progress Reviews',
                freshness: ReportDataFreshness::Live,
                routeName: LearningAnalytics::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'homework_report',
                label: 'Homework Report',
                description: 'Homework assignment, submission, overdue and grading activity — a section of the Learning Analytics page.',
                category: ReportCategory::Learning,
                requiredViewPermission: 'ViewLearningReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: false,
                supportedFilters: [
                    ReportFilterKey::Student, ReportFilterKey::Instructor,
                    ReportFilterKey::HomeworkStatus,
                ],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Homework',
                freshness: ReportDataFreshness::Live,
                routeName: LearningAnalytics::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'finance_overview',
                label: 'Finance Overview',
                description: 'Platform financial reporting (INR-first, Version 1).',
                category: ReportCategory::Finance,
                requiredViewPermission: 'ViewFinanceReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: true,
                supportedFilters: [ReportFilterKey::Currency, ReportFilterKey::Country, ReportFilterKey::PaymentStatus],
                defaultPeriodPreset: ReportingPeriodPreset::ThisMonth,
                dataSourceDomain: 'Booking, Wallet, Earnings',
                freshness: ReportDataFreshness::Live,
                routeName: FinanceOverview::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'wallet_activity',
                label: 'Wallet Activity',
                description: 'Student wallet recharge, debit and refund activity.',
                category: ReportCategory::Wallet,
                requiredViewPermission: 'ViewWalletReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: true,
                supportedFilters: [ReportFilterKey::Currency, ReportFilterKey::WalletTransactionType, ReportFilterKey::WalletTransactionStatus],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Wallet',
                freshness: ReportDataFreshness::Live,
                routeName: WalletRefunds::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'refund_report',
                label: 'Refund Report',
                description: 'Lesson refund decisions, executed wallet refund credits, manual-review queue and linkage. Version 1 refund policy is wallet-credit only.',
                category: ReportCategory::Wallet,
                requiredViewPermission: 'ViewWalletReports',
                requiredExportPermission: 'ExportFinancialReports',
                sensitive: true,
                financial: true,
                supportedFilters: [ReportFilterKey::Currency, ReportFilterKey::LessonOutcome],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Earnings (lesson_financial_dispositions), Wallet (refund ledger credits)',
                freshness: ReportDataFreshness::Live,
                routeName: WalletRefunds::class,
                exportAvailable: true,
                available: true,
            ),
            new ReportDefinition(
                key: 'payment_outcomes',
                label: 'Payment Outcomes',
                description: 'Successful, failed, cancelled and refunded payment collection.',
                category: ReportCategory::Payments,
                requiredViewPermission: 'ViewPaymentReports',
                requiredExportPermission: 'ExportFinancialReports',
                sensitive: false,
                financial: true,
                supportedFilters: [ReportFilterKey::Currency, ReportFilterKey::PaymentStatus],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Booking (Payments)',
                freshness: ReportDataFreshness::Live,
                routeName: PaymentsReconciliation::class,
                exportAvailable: true,
                available: true,
            ),
            new ReportDefinition(
                key: 'earnings_settlements',
                label: 'Earnings & Settlements',
                description: 'Instructor earnings, settlements and withdrawals — instructor compensation remains independently restricted.',
                category: ReportCategory::EarningsSettlements,
                requiredViewPermission: 'ViewInstructorCompensationReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: true,
                supportedFilters: [ReportFilterKey::Currency, ReportFilterKey::Instructor, ReportFilterKey::EarningStatus, ReportFilterKey::SettlementStatus, ReportFilterKey::WithdrawalStatus],
                defaultPeriodPreset: ReportingPeriodPreset::ThisMonth,
                dataSourceDomain: 'Earnings',
                freshness: ReportDataFreshness::Live,
                routeName: InstructorFinancials::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'referral_activity',
                label: 'Referral Activity',
                description: 'Wallet-ledger-confirmed referral credits (Phase 18G). Version 1 has no referral code/campaign/attribution domain — conversion and campaign metrics are unavailable.',
                category: ReportCategory::Referrals,
                requiredViewPermission: 'ViewReferralReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: true,
                supportedFilters: [ReportFilterKey::Currency],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Wallet ledger (referral_credit entries)',
                freshness: ReportDataFreshness::Live,
                routeName: ReferralCommunicationReports::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'review_quality_analytics',
                label: 'Review & Quality Analytics',
                description: 'Review submission rates and quality overview — complementary to the Reviews & Quality Dashboard, which remains the moderation-queue owner (Phase 18G).',
                category: ReportCategory::ReviewsQuality,
                requiredViewPermission: 'ViewReviewQualityReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: false,
                supportedFilters: [ReportFilterKey::Instructor],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Reviews (eligibilities, rating aggregates), Quality',
                freshness: ReportDataFreshness::Live,
                routeName: ReferralCommunicationReports::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'marketplace_supply_demand',
                label: 'Marketplace Supply & Demand',
                description: 'Current-state instructor supply versus period-event booking demand, compared on compatible dimensions only — no score, no ranking (Phase 18H).',
                category: ReportCategory::Marketplace,
                requiredViewPermission: 'ViewMarketplaceReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: false,
                supportedFilters: [ReportFilterKey::Country, ReportFilterKey::Subject, ReportFilterKey::Instructor],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Users (instructor lifecycle), teacher_subjects, teacher_availability, Booking, Learning Goals, student_preferred_subjects',
                freshness: ReportDataFreshness::Live,
                routeName: MarketplaceSupplyDemand::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'reviews_quality_dashboard',
                label: 'Reviews & Quality Dashboard',
                description: 'Review moderation queue, review reports, and instructor quality alerts.',
                category: ReportCategory::ReviewsQuality,
                requiredViewPermission: 'ViewReviewQualityReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: false,
                supportedFilters: [ReportFilterKey::ReviewStatus, ReportFilterKey::ReviewReportStatus, ReportFilterKey::QualityAlertStatus],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Reviews, Quality',
                freshness: ReportDataFreshness::Live,
                routeName: ReviewsQualityDashboard::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'notification_delivery',
                label: 'Notification Activity',
                description: 'In-app notification and dispatch-deduplication activity (Phase 18G). No delivery attempt/provider outcome is recorded in Version 1 — delivery-rate and provider metrics are unavailable.',
                category: ReportCategory::Notifications,
                requiredViewPermission: 'ViewNotificationReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: false,
                supportedFilters: [],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'In-app notifications, notification dispatch idempotency log',
                freshness: ReportDataFreshness::Live,
                routeName: ReferralCommunicationReports::class,
                exportAvailable: false,
                available: true,
            ),
        ];
    }
}
