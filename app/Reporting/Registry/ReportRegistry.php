<?php

declare(strict_types=1);

namespace App\Reporting\Registry;

use App\Filament\Pages\BookingLessonMeetingOperations;
use App\Filament\Pages\BookingReports;
use App\Filament\Pages\ReviewsQualityDashboard;
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
                label: 'Executive Summary',
                description: 'Cross-domain headline indicators for leadership review.',
                category: ReportCategory::Executive,
                requiredViewPermission: 'ViewExecutiveReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: false,
                supportedFilters: [ReportFilterKey::Country, ReportFilterKey::Currency],
                defaultPeriodPreset: ReportingPeriodPreset::ThisMonth,
                dataSourceDomain: 'Cross-domain',
                freshness: ReportDataFreshness::Live,
                routeName: null,
                exportAvailable: false,
                available: false,
            ),
            new ReportDefinition(
                key: 'booking_lesson_meeting_operations',
                label: 'Booking, Lesson & Meeting Operations',
                description: 'Actionable day-to-day operational items: today\'s lessons, cancellations, no-shows, technical issues, and meeting creation failures.',
                category: ReportCategory::Operations,
                requiredViewPermission: 'ViewOperationalReports',
                requiredExportPermission: null,
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
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'student_activity',
                label: 'Student Activity',
                description: 'Student lifecycle and activity reporting.',
                category: ReportCategory::Students,
                requiredViewPermission: 'ViewStudentReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: false,
                supportedFilters: [ReportFilterKey::Country, ReportFilterKey::Student],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Users',
                freshness: ReportDataFreshness::Live,
                routeName: null,
                exportAvailable: false,
                available: false,
            ),
            new ReportDefinition(
                key: 'instructor_activity',
                label: 'Instructor Activity',
                description: 'Instructor lifecycle and activity reporting.',
                category: ReportCategory::Instructors,
                requiredViewPermission: 'ViewInstructorReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: false,
                supportedFilters: [ReportFilterKey::Country, ReportFilterKey::Instructor, ReportFilterKey::Subject],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Users',
                freshness: ReportDataFreshness::Live,
                routeName: null,
                exportAvailable: false,
                available: false,
            ),
            new ReportDefinition(
                key: 'booking_lesson_kpis',
                label: 'Booking Reports',
                description: 'Booking KPIs, popular subjects/time slots, and teacher utilization.',
                category: ReportCategory::BookingsLessons,
                requiredViewPermission: 'ViewBookingLessonReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: false,
                supportedFilters: [ReportFilterKey::BookingType, ReportFilterKey::RecurrenceType, ReportFilterKey::BookingStatus, ReportFilterKey::Country, ReportFilterKey::Subject],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Booking',
                freshness: ReportDataFreshness::Live,
                routeName: BookingReports::class,
                exportAvailable: false,
                available: true,
            ),
            new ReportDefinition(
                key: 'meeting_reliability',
                label: 'Meeting Reliability',
                description: 'Meeting scheduling and provider reliability.',
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
                label: 'Learning Progress',
                description: 'Homework and learning plan progress.',
                category: ReportCategory::Learning,
                requiredViewPermission: 'ViewLearningReports',
                requiredExportPermission: null,
                sensitive: true,
                financial: false,
                supportedFilters: [ReportFilterKey::Student, ReportFilterKey::Subject],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Homework, Learning Plans',
                freshness: ReportDataFreshness::Live,
                routeName: null,
                exportAvailable: false,
                available: false,
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
                routeName: null,
                exportAvailable: false,
                available: false,
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
                routeName: null,
                exportAvailable: false,
                available: false,
            ),
            new ReportDefinition(
                key: 'payment_outcomes',
                label: 'Payment Outcomes',
                description: 'Successful, failed, cancelled and refunded payment collection.',
                category: ReportCategory::Payments,
                requiredViewPermission: 'ViewPaymentReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: true,
                supportedFilters: [ReportFilterKey::Currency, ReportFilterKey::PaymentStatus],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Booking (Payments)',
                freshness: ReportDataFreshness::Live,
                routeName: null,
                exportAvailable: false,
                available: false,
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
                routeName: null,
                exportAvailable: false,
                available: false,
            ),
            new ReportDefinition(
                key: 'referral_activity',
                label: 'Referral Activity',
                description: 'Referral program activity.',
                category: ReportCategory::Referrals,
                requiredViewPermission: 'ViewReferralReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: false,
                supportedFilters: [],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Referrals (no authoritative status enum yet)',
                freshness: ReportDataFreshness::Live,
                routeName: null,
                exportAvailable: false,
                available: false,
            ),
            new ReportDefinition(
                key: 'marketplace_supply_demand',
                label: 'Marketplace Supply & Demand',
                description: 'Marketplace supply and demand indicators by country and subject.',
                category: ReportCategory::Marketplace,
                requiredViewPermission: 'ViewMarketplaceReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: false,
                supportedFilters: [ReportFilterKey::Country, ReportFilterKey::Subject],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Booking, Users',
                freshness: ReportDataFreshness::Live,
                routeName: null,
                exportAvailable: false,
                available: false,
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
                label: 'Notification Delivery',
                description: 'Notification delivery reporting.',
                category: ReportCategory::Notifications,
                requiredViewPermission: 'ViewNotificationReports',
                requiredExportPermission: null,
                sensitive: false,
                financial: false,
                supportedFilters: [],
                defaultPeriodPreset: ReportingPeriodPreset::Last30Days,
                dataSourceDomain: 'Notifications (no formal status enum yet)',
                freshness: ReportDataFreshness::Live,
                routeName: null,
                exportAvailable: false,
                available: false,
            ),
        ];
    }
}
