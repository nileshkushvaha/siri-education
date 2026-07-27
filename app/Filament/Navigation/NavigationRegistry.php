<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use App\Filament\Pages\BookingLessonMeetingOperations;
use App\Filament\Pages\BookingReports;
use App\Filament\Pages\CacheManagerPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ExecutiveKpiOverview;
use App\Filament\Pages\FinanceOverview;
use App\Filament\Pages\InstructorFinancials;
use App\Filament\Pages\InstructorPerformance;
use App\Filament\Pages\LearningAnalytics;
use App\Filament\Pages\MarketplaceSupplyDemand;
use App\Filament\Pages\PaymentsReconciliation;
use App\Filament\Pages\QueueMonitorPage;
use App\Filament\Pages\RechargeMonitoring;
use App\Filament\Pages\ReferralCommunicationReports;
use App\Filament\Pages\ReportingHub;
use App\Filament\Pages\ReviewsQualityDashboard;
use App\Filament\Pages\SchedulerMonitorPage;
use App\Filament\Pages\Security\AccountProtectionPage;
use App\Filament\Pages\Security\AuthenticationPage;
use App\Filament\Pages\Security\LoginSecurityPage;
use App\Filament\Pages\Security\PasswordPolicyPage;
use App\Filament\Pages\Security\RegistrationPage;
use App\Filament\Pages\Security\SessionPage;
use App\Filament\Pages\Settings\DemoConversionIncentiveSettingsPage;
use App\Filament\Pages\Settings\GeneralSettingsPage;
use App\Filament\Pages\Settings\HomeworkReminderSettingsPage;
use App\Filament\Pages\Settings\InstructorEarningSettingsPage;
use App\Filament\Pages\Settings\MailSettingsPage;
use App\Filament\Pages\Settings\MeetingSettingsPage;
use App\Filament\Pages\Settings\PaymentAdvancedPage;
use App\Filament\Pages\Settings\PaymentBankAccountPage;
use App\Filament\Pages\Settings\PaymentConfigurationPage;
use App\Filament\Pages\Settings\PaymentGatewayPage;
use App\Filament\Pages\Settings\PlatformFoundationSettingsPage;
use App\Filament\Pages\Settings\RazorpayXPayoutSettingsPage;
use App\Filament\Pages\Settings\ReviewQualitySettingsPage;
use App\Filament\Pages\Settings\SeoSettingsPage;
use App\Filament\Pages\StudentEngagement;
use App\Filament\Pages\WalletRefunds;
use App\Filament\Resources\Academic\AcademicCategoryResource;
use App\Filament\Resources\Academic\AcademicLevelResource;
use App\Filament\Resources\Academic\InstructorSubjectTopicResource;
use App\Filament\Resources\Academic\SkillLevelResource;
use App\Filament\Resources\Academic\SubjectResource;
use App\Filament\Resources\Academic\SubjectTopicResource;
use App\Filament\Resources\ActivityLog\ActivityLogResource;
use App\Filament\Resources\BookingPaymentReconciliationIssues\BookingPaymentReconciliationIssueResource;
use App\Filament\Resources\BookingPayments\BookingPaymentResource;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\BookingTypes\BookingTypeResource;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\Countries\CountryResource;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\EmailLogs\EmailLogResource;
use App\Filament\Resources\Faq\FaqCategoryResource;
use App\Filament\Resources\Faq\FaqResource;
use App\Filament\Resources\InstructorCompensationAgreements\InstructorCompensationAgreementResource;
use App\Filament\Resources\InstructorCompensationExceptions\InstructorCompensationExceptionResource;
use App\Filament\Resources\InstructorDocumentRequirements\InstructorDocumentRequirementResource;
use App\Filament\Resources\InstructorEarnings\InstructorEarningResource;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use App\Filament\Resources\InstructorPayoutAttempts\InstructorPayoutAttemptResource;
use App\Filament\Resources\InstructorPayoutMethods\InstructorPayoutMethodResource;
use App\Filament\Resources\InstructorPayoutReconciliationIssues\InstructorPayoutReconciliationIssueResource;
use App\Filament\Resources\InstructorSettlementBatches\InstructorSettlementBatchResource;
use App\Filament\Resources\InstructorWaitlistEntries\InstructorWaitlistEntryResource;
use App\Filament\Resources\InstructorWithdrawalRequests\InstructorWithdrawalRequestResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Languages\LanguageResource;
use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\LoginHistory\LoginHistoryResource;
use App\Filament\Resources\Navigation\NavigationResource;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\OperationalAlerts\OperationalAlertResource;
use App\Filament\Resources\PageBlocks\PageBlockResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\PostCategories\PostCategoryResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\PromotionalCreditCampaigns\PromotionalCreditCampaignResource;
use App\Filament\Resources\PromotionalCreditIssuances\PromotionalCreditIssuanceResource;
use App\Filament\Resources\Recordings\RecordingResource;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Filament\Resources\ReferralAttributions\ReferralAttributionResource;
use App\Filament\Resources\ReferralCampaigns\ReferralCampaignResource;
use App\Filament\Resources\ReferralCodes\ReferralCodeResource;
use App\Filament\Resources\ReferralRewards\ReferralRewardResource;
use App\Filament\Resources\ReviewTags\ReviewTagResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\States\StateResource;
use App\Filament\Resources\StudentLearningGoals\StudentLearningGoalResource;
use App\Filament\Resources\StudentLearningPlans\StudentLearningPlanResource;
use App\Filament\Resources\StudentLessonPrices\StudentLessonPriceResource;
use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Filament\Resources\SuspiciousActivityFlags\SuspiciousActivityFlagResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\TeacherAvailability\TeacherAvailabilityResource;
use App\Filament\Resources\TeacherLeave\TeacherLeaveResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Wallets\WalletResource;

/**
 * Single source of truth for admin sidebar placement.
 *
 * What this does and does not do:
 *  - Decides GROUP, SUBGROUP (informational), LABEL and SORT for every
 *    Resource/Page that `use`s HasCentralizedNavigation.
 *  - Never decides visibility. `canAccess()` / `shouldRegisterNavigation()`
 *    / Policies on each class remain the only authority on who sees what;
 *    this registry runs only after that check already passed.
 *  - Never points two entries at the same underlying route unless
 *    explicitly documented as a `crossLinkedFrom` (metadata only, no
 *    second nav item is created for it).
 *
 * Adding a new module: add one entry here and `use HasCentralizedNavigation;`
 * on the new Resource/Page. No other file needs to change.
 */
final class NavigationRegistry
{
    /**
     * @return array<class-string, NavigationDestination>
     */
    public static function destinations(): array
    {
        return [
            // ── Home ─────────────────────────────────────────────────────
            // Ungrouped by design (group: null) — a lone "Dashboard" link
            // doesn't need a collapsible section header. Unchanged from
            // today's behavior.
            Dashboard::class => new NavigationDestination(
                id: 'home.dashboard',
                label: 'Dashboard',
                group: null,
                subgroup: null,
                sort: -2,
                previousGroup: null,
                previousLabel: 'Dashboard',
            ),

            // ── People ───────────────────────────────────────────────────
            UserResource::class => new NavigationDestination(
                id: 'people.users',
                label: 'Users',
                group: 'People',
                subgroup: 'Users',
                sort: 1,
                previousGroup: 'Users & Access',
                previousLabel: 'Users',
            ),
            InstructorOnboardingResource::class => new NavigationDestination(
                id: 'people.instructors.onboarding',
                label: 'Onboarding',
                group: 'People',
                subgroup: 'Instructors',
                sort: 2,
                previousGroup: 'Instructor',
                previousLabel: 'Onboarding',
            ),
            InstructorDocumentRequirementResource::class => new NavigationDestination(
                id: 'people.instructors.document-requirements',
                label: 'Document Requirements',
                group: 'People',
                subgroup: 'Instructors',
                sort: 3,
                previousGroup: 'Instructor',
                previousLabel: 'Document Requirements',
            ),
            StudentLearningGoalResource::class => new NavigationDestination(
                id: 'people.students.learning-goals',
                label: 'Learning Goals',
                group: 'People',
                subgroup: 'Students',
                sort: 4,
                previousGroup: 'Students',
                previousLabel: 'Learning Goals',
            ),
            StudentLearningPlanResource::class => new NavigationDestination(
                id: 'people.students.learning-plans',
                label: 'Learning Plans',
                group: 'People',
                subgroup: 'Students',
                sort: 5,
                previousGroup: 'Students',
                previousLabel: 'Learning Plans',
            ),

            // ── Academics ────────────────────────────────────────────────
            AcademicCategoryResource::class => new NavigationDestination(
                id: 'academics.categories',
                label: 'Academic Categories',
                group: 'Academics',
                subgroup: null,
                sort: 1,
                previousGroup: 'Academic',
                previousLabel: 'Academic Categories',
            ),
            SubjectResource::class => new NavigationDestination(
                id: 'academics.subjects',
                label: 'Subjects',
                group: 'Academics',
                subgroup: null,
                sort: 2,
                previousGroup: 'Academic',
                previousLabel: 'Subjects',
            ),
            SubjectTopicResource::class => new NavigationDestination(
                id: 'academics.subject-topics',
                label: 'Subject Topics',
                group: 'Academics',
                subgroup: null,
                sort: 3,
                previousGroup: 'Academic',
                previousLabel: 'Subject Topics',
            ),
            AcademicLevelResource::class => new NavigationDestination(
                id: 'academics.academic-levels',
                label: 'Academic Levels',
                group: 'Academics',
                subgroup: null,
                sort: 4,
                previousGroup: 'Academic',
                previousLabel: 'Academic Levels',
            ),
            SkillLevelResource::class => new NavigationDestination(
                id: 'academics.skill-levels',
                label: 'Skill Levels',
                group: 'Academics',
                subgroup: null,
                sort: 5,
                previousGroup: 'Academic',
                previousLabel: 'Skill Levels',
            ),
            // Canonical entry (also contextually relevant to People →
            // Instructors — cross-linked from there, not duplicated).
            InstructorSubjectTopicResource::class => new NavigationDestination(
                id: 'academics.instructor-topic-coverage',
                label: 'Instructor Topic Coverage',
                group: 'Academics',
                subgroup: null,
                sort: 6,
                crossLinkedFrom: 'People → Instructors',
                previousGroup: 'Academic',
                previousLabel: 'Instructor Topic Coverage',
            ),

            // ── Operations ───────────────────────────────────────────────
            TeacherAvailabilityResource::class => new NavigationDestination(
                id: 'operations.scheduling.teacher-availability',
                label: 'Teacher Availability',
                group: 'Operations',
                subgroup: 'Scheduling',
                sort: 1,
                previousGroup: 'Scheduling',
                previousLabel: 'Teacher Availability',
            ),
            TeacherLeaveResource::class => new NavigationDestination(
                id: 'operations.scheduling.teacher-leave',
                label: 'Teacher Leave',
                group: 'Operations',
                subgroup: 'Scheduling',
                sort: 2,
                previousGroup: 'Scheduling',
                previousLabel: 'Teacher Leave',
            ),
            BookingResource::class => new NavigationDestination(
                id: 'operations.lessons-bookings.bookings',
                label: 'Bookings',
                group: 'Operations',
                subgroup: 'Lessons & Bookings',
                sort: 3,
                previousGroup: 'Booking',
                previousLabel: 'Bookings',
            ),
            BookingTypeResource::class => new NavigationDestination(
                id: 'operations.lessons-bookings.booking-types',
                label: 'Booking Types',
                group: 'Operations',
                subgroup: 'Lessons & Bookings',
                sort: 4,
                previousGroup: 'Booking',
                previousLabel: 'Booking Types',
            ),
            LessonResource::class => new NavigationDestination(
                id: 'operations.lessons-bookings.lessons',
                label: 'Lessons',
                group: 'Operations',
                subgroup: 'Lessons & Bookings',
                sort: 5,
                previousGroup: 'Booking',
                previousLabel: 'Lessons',
            ),
            InstructorWaitlistEntryResource::class => new NavigationDestination(
                id: 'operations.lessons-bookings.waitlist',
                label: 'Waitlist',
                group: 'Operations',
                subgroup: 'Lessons & Bookings',
                sort: 6,
                previousGroup: 'Booking',
                previousLabel: 'Waitlist',
            ),
            RecordingResource::class => new NavigationDestination(
                id: 'operations.lessons-bookings.recordings',
                label: 'Recordings',
                group: 'Operations',
                subgroup: 'Lessons & Bookings',
                sort: 7,
                previousGroup: 'Booking',
                previousLabel: 'Recordings',
            ),
            ConversationResource::class => new NavigationDestination(
                id: 'operations.support.conversations',
                label: 'Conversations',
                group: 'Operations',
                subgroup: 'Support',
                sort: 8,
                previousGroup: 'Support',
                previousLabel: 'Conversations',
            ),
            SupportCaseResource::class => new NavigationDestination(
                id: 'operations.support.support-cases',
                label: 'Support Cases',
                group: 'Operations',
                subgroup: 'Support',
                sort: 9,
                previousGroup: 'Support',
                previousLabel: 'Support Cases',
            ),

            // ── Finance ──────────────────────────────────────────────────
            BookingPaymentResource::class => new NavigationDestination(
                id: 'finance.billing.payments',
                label: 'Payments',
                group: 'Finance',
                subgroup: 'Billing & Payments',
                sort: 1,
                previousGroup: 'Booking',
                previousLabel: 'Payments',
            ),
            BookingPaymentReconciliationIssueResource::class => new NavigationDestination(
                id: 'finance.billing.payment-reconciliation',
                label: 'Payment Reconciliation',
                group: 'Finance',
                subgroup: 'Billing & Payments',
                sort: 2,
                previousGroup: 'Booking',
                previousLabel: 'Payment Reconciliation',
            ),
            InvoiceResource::class => new NavigationDestination(
                id: 'finance.billing.invoices',
                label: 'Invoices',
                group: 'Finance',
                subgroup: 'Billing & Payments',
                sort: 3,
                previousGroup: 'Booking',
                previousLabel: 'Invoices',
            ),
            StudentLessonPriceResource::class => new NavigationDestination(
                id: 'finance.billing.student-lesson-prices',
                label: 'Student Lesson Prices',
                group: 'Finance',
                subgroup: 'Billing & Payments',
                sort: 4,
                previousGroup: 'Booking',
                previousLabel: 'Student Lesson Prices',
            ),
            WalletResource::class => new NavigationDestination(
                id: 'finance.wallets',
                label: 'Wallets',
                group: 'Finance',
                subgroup: 'Wallets',
                sort: 5,
                previousGroup: 'Wallet',
                previousLabel: 'Wallets',
            ),
            InstructorCompensationAgreementResource::class => new NavigationDestination(
                id: 'finance.earnings-payouts.compensation-agreements',
                label: 'Compensation Agreements',
                group: 'Finance',
                subgroup: 'Instructor Earnings & Payouts',
                sort: 6,
                previousGroup: 'Earnings',
                previousLabel: 'Compensation Agreements',
            ),
            InstructorCompensationExceptionResource::class => new NavigationDestination(
                id: 'finance.earnings-payouts.compensation-exceptions',
                label: 'Compensation Exceptions',
                group: 'Finance',
                subgroup: 'Instructor Earnings & Payouts',
                sort: 7,
                previousGroup: 'Earnings',
                previousLabel: 'Compensation Exceptions',
            ),
            // Ledger/records resource — distinct from InstructorEarningSettingsPage
            // (global payout-rate configuration, kept in Finance Configuration
            // below). Label deliberately unchanged/undisambiguated here since,
            // per user decision, the settings page is the one being renamed.
            InstructorEarningResource::class => new NavigationDestination(
                id: 'finance.earnings-payouts.instructor-earnings',
                label: 'Instructor Earnings',
                group: 'Finance',
                subgroup: 'Instructor Earnings & Payouts',
                sort: 8,
                previousGroup: 'Earnings',
                previousLabel: 'Instructor Earnings',
            ),
            InstructorSettlementBatchResource::class => new NavigationDestination(
                id: 'finance.earnings-payouts.settlement-batches',
                label: 'Settlement Batches',
                group: 'Finance',
                subgroup: 'Instructor Earnings & Payouts',
                sort: 9,
                previousGroup: 'Earnings',
                previousLabel: 'Settlement Batches',
            ),
            InstructorPayoutMethodResource::class => new NavigationDestination(
                id: 'finance.earnings-payouts.payout-methods',
                label: 'Payout Methods',
                group: 'Finance',
                subgroup: 'Instructor Earnings & Payouts',
                sort: 10,
                previousGroup: 'Earnings',
                previousLabel: 'Payout Methods',
            ),
            InstructorWithdrawalRequestResource::class => new NavigationDestination(
                id: 'finance.earnings-payouts.withdrawal-requests',
                label: 'Withdrawal Requests',
                group: 'Finance',
                subgroup: 'Instructor Earnings & Payouts',
                sort: 11,
                previousGroup: 'Earnings',
                previousLabel: 'Withdrawal Requests',
            ),
            InstructorPayoutAttemptResource::class => new NavigationDestination(
                id: 'finance.earnings-payouts.payout-attempts',
                label: 'Payout Attempts',
                group: 'Finance',
                subgroup: 'Instructor Earnings & Payouts',
                sort: 12,
                previousGroup: 'Earnings',
                previousLabel: 'Payout Attempts',
            ),
            InstructorPayoutReconciliationIssueResource::class => new NavigationDestination(
                id: 'finance.earnings-payouts.payout-reconciliation',
                label: 'Payout Reconciliation',
                group: 'Finance',
                subgroup: 'Instructor Earnings & Payouts',
                sort: 13,
                previousGroup: 'Earnings',
                previousLabel: 'Payout Reconciliation',
            ),
            PaymentBankAccountPage::class => new NavigationDestination(
                id: 'finance.configuration.bank-account',
                label: 'Bank Account',
                group: 'Finance',
                subgroup: 'Finance Configuration',
                sort: 15,
                previousGroup: 'Finance',
                previousLabel: 'Bank Account',
            ),
            PaymentGatewayPage::class => new NavigationDestination(
                id: 'finance.configuration.payment-gateways',
                label: 'Payment Gateways',
                group: 'Finance',
                subgroup: 'Finance Configuration',
                sort: 16,
                previousGroup: 'Finance',
                previousLabel: 'Payment Gateways',
            ),
            PaymentConfigurationPage::class => new NavigationDestination(
                id: 'finance.configuration.payment-configuration',
                label: 'Payment Configuration',
                group: 'Finance',
                subgroup: 'Finance Configuration',
                sort: 17,
                previousGroup: 'Finance',
                previousLabel: 'Payment Configuration',
            ),
            PaymentAdvancedPage::class => new NavigationDestination(
                id: 'finance.configuration.advanced',
                label: 'Advanced Finance Settings',
                group: 'Finance',
                subgroup: 'Finance Configuration',
                sort: 18,
                previousGroup: 'Finance',
                previousLabel: 'Advanced',
            ),
            // Global instructor payout-rate/compensation rule configuration —
            // distinct business function from the InstructorEarningResource
            // ledger above. Placed and labeled per explicit user decision.
            InstructorEarningSettingsPage::class => new NavigationDestination(
                id: 'finance.configuration.instructor-earnings-rules',
                label: 'Instructor Earnings Rules',
                group: 'Finance',
                subgroup: 'Finance Configuration',
                sort: 19,
                previousGroup: 'Platform',
                previousLabel: 'Instructor Earnings',
            ),
            // Inspected — this page is configuration-only
            // (provider credentials, environment, defaults, IP allowlisting,
            // webhook rotation; its "check health"/"validate configuration"
            // actions verify config structure, never execute or list
            // individual payouts — that's InstructorPayoutAttemptResource /
            // InstructorWithdrawalRequestResource /
            // InstructorPayoutReconciliationIssueResource). Per that
            // finding, this belongs in Finance Configuration, not
            // Instructor Earnings & Payouts.
            RazorpayXPayoutSettingsPage::class => new NavigationDestination(
                id: 'finance.configuration.razorpayx-payout-settings',
                label: 'RazorpayX Payout Settings',
                group: 'Finance',
                subgroup: 'Finance Configuration',
                sort: 20,
                previousGroup: 'Platform',
                previousLabel: 'RazorpayX Payouts',
            ),

            // ── Growth ───────────────────────────────────────────────────
            ReferralCampaignResource::class => new NavigationDestination(
                id: 'growth.referrals.campaigns',
                label: 'Referral Campaigns',
                group: 'Growth',
                subgroup: 'Referrals',
                sort: 1,
                previousGroup: 'Referral',
                previousLabel: 'Referral Campaigns',
            ),
            ReferralRewardResource::class => new NavigationDestination(
                id: 'growth.referrals.rewards',
                label: 'Referral Rewards',
                group: 'Growth',
                subgroup: 'Referrals',
                sort: 2,
                previousGroup: 'Referral',
                previousLabel: 'Referral Rewards',
            ),
            ReferralAttributionResource::class => new NavigationDestination(
                id: 'growth.referrals.attributions',
                label: 'Referral Attributions',
                group: 'Growth',
                subgroup: 'Referrals',
                sort: 3,
                previousGroup: 'Referral',
                previousLabel: 'Referral Attributions',
            ),
            ReferralCodeResource::class => new NavigationDestination(
                id: 'growth.referrals.codes',
                label: 'Referral Codes',
                group: 'Growth',
                subgroup: 'Referrals',
                sort: 4,
                previousGroup: 'Referral',
                previousLabel: 'Referral Codes',
            ),
            PromotionalCreditCampaignResource::class => new NavigationDestination(
                id: 'growth.promotions.campaigns',
                label: 'Promotional Campaigns',
                group: 'Growth',
                subgroup: 'Promotions',
                sort: 5,
                previousGroup: 'Referral',
                previousLabel: 'Promotional Campaigns',
            ),
            PromotionalCreditIssuanceResource::class => new NavigationDestination(
                id: 'growth.promotions.credit-issuances',
                label: 'Promotional Credit Issuances',
                group: 'Growth',
                subgroup: 'Promotions',
                sort: 6,
                previousGroup: 'Referral',
                previousLabel: 'Promotional Credit Issuances',
            ),
            DemoConversionIncentiveSettingsPage::class => new NavigationDestination(
                id: 'growth.promotions.demo-conversion-incentive',
                label: 'Demo Conversion Incentive',
                group: 'Growth',
                subgroup: 'Promotions',
                sort: 7,
                previousGroup: 'Platform',
                previousLabel: 'Demo Conversion Incentive',
            ),

            // ── Content & Communication ──────────────────────────────────
            PageResource::class => new NavigationDestination(
                id: 'content.pages',
                label: 'Pages',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 1,
                previousGroup: 'Content',
                previousLabel: 'Pages',
            ),
            PostCategoryResource::class => new NavigationDestination(
                id: 'content.categories',
                label: 'Categories',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 2,
                previousGroup: 'Content',
                previousLabel: 'Categories',
            ),
            PostResource::class => new NavigationDestination(
                id: 'content.posts',
                label: 'Posts',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 3,
                previousGroup: 'Content',
                previousLabel: 'Posts',
            ),
            TagResource::class => new NavigationDestination(
                id: 'content.tags',
                label: 'Tags',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 4,
                previousGroup: 'Content',
                previousLabel: 'Tags',
            ),
            FaqCategoryResource::class => new NavigationDestination(
                id: 'content.faq-categories',
                label: 'FAQ Categories',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 5,
                previousGroup: 'Content',
                previousLabel: 'FAQ Categories',
            ),
            FaqResource::class => new NavigationDestination(
                id: 'content.faqs',
                label: 'FAQs',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 6,
                previousGroup: 'Content',
                previousLabel: 'FAQs',
            ),
            NavigationResource::class => new NavigationDestination(
                id: 'content.navigation',
                label: 'Navigation',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 7,
                previousGroup: 'Content',
                previousLabel: 'Navigation',
            ),
            PageBlockResource::class => new NavigationDestination(
                id: 'content.content-blocks',
                label: 'Content Blocks',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 8,
                previousGroup: 'Content',
                previousLabel: 'Content Blocks',
            ),
            RedirectResource::class => new NavigationDestination(
                id: 'content.redirects',
                label: 'Redirects',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 9,
                previousGroup: 'Content',
                previousLabel: 'Redirects',
            ),
            SeoSettingsPage::class => new NavigationDestination(
                id: 'content.seo',
                label: 'SEO',
                group: 'Content & Communication',
                subgroup: 'Content',
                sort: 10,
                previousGroup: 'Platform',
                previousLabel: 'SEO',
            ),
            MailSettingsPage::class => new NavigationDestination(
                id: 'content.communication.mail',
                label: 'Mail',
                group: 'Content & Communication',
                subgroup: 'Communication',
                sort: 11,
                previousGroup: 'Platform',
                previousLabel: 'Mail',
            ),
            EmailLogResource::class => new NavigationDestination(
                id: 'content.communication.email-logs',
                label: 'Email Logs',
                group: 'Content & Communication',
                subgroup: 'Communication',
                sort: 12,
                previousGroup: 'Communication',
                previousLabel: 'Email Logs',
            ),
            NotificationTemplateResource::class => new NavigationDestination(
                id: 'content.communication.notification-templates',
                label: 'Notification Templates',
                group: 'Content & Communication',
                subgroup: 'Communication',
                sort: 13,
                previousGroup: 'Communication',
                previousLabel: 'Notification Templates',
            ),
            HomeworkReminderSettingsPage::class => new NavigationDestination(
                id: 'content.communication.homework-reminders',
                label: 'Homework Reminders',
                group: 'Content & Communication',
                subgroup: 'Communication',
                sort: 14,
                previousGroup: 'Platform',
                previousLabel: 'Homework Reminders',
            ),

            // ── Quality & Compliance ─────────────────────────────────────
            // Operational moderation dashboard (real approve/reject/hide/
            // restore actions) — relabeled per user decision to disambiguate
            // from the Settings → Quality configuration page below. Also
            // conceptually relevant to Analytics → Operations; cross-linked
            // from there rather than duplicated.
            ReviewsQualityDashboard::class => new NavigationDestination(
                id: 'quality.review-operations',
                label: 'Review Operations',
                group: 'Quality & Compliance',
                subgroup: null,
                sort: 1,
                crossLinkedFrom: 'Analytics → Operations',
                previousGroup: 'Reports',
                previousLabel: 'Reviews & Quality',
            ),
            ReviewTagResource::class => new NavigationDestination(
                id: 'quality.review-tags',
                label: 'Review Tags',
                group: 'Quality & Compliance',
                subgroup: null,
                sort: 2,
                previousGroup: 'Reports',
                previousLabel: 'Review Tags',
            ),
            SuspiciousActivityFlagResource::class => new NavigationDestination(
                id: 'quality.compliance-flags',
                label: 'Compliance Flags',
                group: 'Quality & Compliance',
                subgroup: null,
                sort: 3,
                previousGroup: 'Compliance',
                previousLabel: 'Compliance Flags',
            ),

            // ── Analytics ────────────────────────────────────────────────
            ReportingHub::class => new NavigationDestination(
                id: 'analytics.operations.reporting-hub',
                label: 'Reporting Hub',
                group: 'Analytics',
                subgroup: 'Operations',
                sort: 1,
                previousGroup: 'Reports',
                previousLabel: 'Reporting Hub',
            ),
            BookingLessonMeetingOperations::class => new NavigationDestination(
                id: 'analytics.operations.booking-lesson-meeting-operations',
                label: 'Booking, Lesson & Meeting Operations',
                group: 'Analytics',
                subgroup: 'Operations',
                sort: 2,
                previousGroup: 'Reports',
                previousLabel: 'Booking, Lesson & Meeting Operations',
            ),
            BookingReports::class => new NavigationDestination(
                id: 'analytics.operations.booking-reports',
                label: 'Booking Reports',
                group: 'Analytics',
                subgroup: 'Operations',
                sort: 3,
                previousGroup: 'Reports',
                previousLabel: 'Booking Reports',
            ),
            StudentEngagement::class => new NavigationDestination(
                id: 'analytics.operations.student-engagement',
                label: 'Student Engagement',
                group: 'Analytics',
                subgroup: 'Operations',
                sort: 4,
                previousGroup: 'Reports',
                previousLabel: 'Student Engagement',
            ),
            InstructorPerformance::class => new NavigationDestination(
                id: 'analytics.instructor-learning.instructor-performance',
                label: 'Instructor Performance',
                group: 'Analytics',
                subgroup: 'Instructor & Learning',
                sort: 5,
                previousGroup: 'Reports',
                previousLabel: 'Instructor Performance',
            ),
            LearningAnalytics::class => new NavigationDestination(
                id: 'analytics.instructor-learning.learning-analytics',
                label: 'Learning Analytics',
                group: 'Analytics',
                subgroup: 'Instructor & Learning',
                sort: 6,
                previousGroup: 'Reports',
                previousLabel: 'Learning Analytics',
            ),
            FinanceOverview::class => new NavigationDestination(
                id: 'analytics.finance.finance-overview',
                label: 'Finance Overview',
                group: 'Analytics',
                subgroup: 'Finance',
                sort: 7,
                previousGroup: 'Reports',
                previousLabel: 'Finance Overview',
            ),
            WalletRefunds::class => new NavigationDestination(
                id: 'analytics.finance.wallet-refunds',
                label: 'Wallet & Refunds',
                group: 'Analytics',
                subgroup: 'Finance',
                sort: 8,
                previousGroup: 'Reports',
                previousLabel: 'Wallet & Refunds',
            ),
            PaymentsReconciliation::class => new NavigationDestination(
                id: 'analytics.finance.payments-reconciliation',
                label: 'Payments & Reconciliation',
                group: 'Analytics',
                subgroup: 'Finance',
                sort: 9,
                previousGroup: 'Reports',
                previousLabel: 'Payments & Reconciliation',
            ),
            RechargeMonitoring::class => new NavigationDestination(
                id: 'analytics.finance.recharge-monitoring',
                label: 'Recharge Monitoring',
                group: 'Analytics',
                subgroup: 'Finance',
                sort: 10,
                previousGroup: 'Reports',
                previousLabel: 'Recharge Monitoring',
            ),
            InstructorFinancials::class => new NavigationDestination(
                id: 'analytics.finance.instructor-financials',
                label: 'Instructor Financials',
                group: 'Analytics',
                subgroup: 'Finance',
                sort: 11,
                previousGroup: 'Reports',
                previousLabel: 'Instructor Financials',
            ),
            ReferralCommunicationReports::class => new NavigationDestination(
                id: 'analytics.growth-marketplace.referrals-communications',
                label: 'Referrals & Communications',
                group: 'Analytics',
                subgroup: 'Growth & Marketplace',
                sort: 12,
                previousGroup: 'Reports',
                previousLabel: 'Referrals & Communications',
            ),
            MarketplaceSupplyDemand::class => new NavigationDestination(
                id: 'analytics.growth-marketplace.marketplace-supply-demand',
                label: 'Marketplace Supply & Demand',
                group: 'Analytics',
                subgroup: 'Growth & Marketplace',
                sort: 13,
                previousGroup: 'Reports',
                previousLabel: 'Marketplace Supply & Demand',
            ),
            ExecutiveKpiOverview::class => new NavigationDestination(
                id: 'analytics.executive.kpi-overview',
                label: 'Executive KPI Overview',
                group: 'Analytics',
                subgroup: 'Executive',
                sort: 14,
                previousGroup: 'Reports',
                previousLabel: 'Executive KPI Overview',
            ),

            // ── Settings ─────────────────────────────────────────────────
            GeneralSettingsPage::class => new NavigationDestination(
                id: 'settings.platform.general',
                label: 'General',
                group: 'Settings',
                subgroup: 'Platform',
                sort: 1,
                previousGroup: 'Platform',
                previousLabel: 'General',
            ),
            CountryResource::class => new NavigationDestination(
                id: 'settings.platform.countries',
                label: 'Countries',
                group: 'Settings',
                subgroup: 'Platform',
                sort: 2,
                previousGroup: 'Platform',
                previousLabel: 'Countries',
            ),
            StateResource::class => new NavigationDestination(
                id: 'settings.platform.states',
                label: 'States',
                group: 'Settings',
                subgroup: 'Platform',
                sort: 3,
                previousGroup: 'Platform',
                previousLabel: 'States',
            ),
            CurrencyResource::class => new NavigationDestination(
                id: 'settings.platform.currencies',
                label: 'Currencies',
                group: 'Settings',
                subgroup: 'Platform',
                sort: 4,
                previousGroup: 'Platform',
                previousLabel: 'Currencies',
            ),
            LanguageResource::class => new NavigationDestination(
                id: 'settings.platform.languages',
                label: 'Languages',
                group: 'Settings',
                subgroup: 'Platform',
                sort: 5,
                previousGroup: 'Platform',
                previousLabel: 'Languages',
            ),
            PlatformFoundationSettingsPage::class => new NavigationDestination(
                id: 'settings.platform.platform-foundation',
                label: 'Platform Foundation',
                group: 'Settings',
                subgroup: 'Platform',
                sort: 6,
                previousGroup: 'Platform',
                previousLabel: 'Platform Foundation',
            ),
            // Inspected — this page is configuration-only
            // (provider toggles, credentials, auto-creation rules, join-link
            // visibility; its "actions" are configuration validation checks,
            // not operational meeting management — no separate meeting-
            // record CRUD page exists anywhere in the panel). Per that
            // finding, this belongs in Settings → Platform, not Operations.
            MeetingSettingsPage::class => new NavigationDestination(
                id: 'settings.platform.meeting-settings',
                label: 'Meeting Settings',
                group: 'Settings',
                subgroup: 'Platform',
                sort: 7,
                previousGroup: 'Platform',
                previousLabel: 'Meetings',
            ),
            AuthenticationPage::class => new NavigationDestination(
                id: 'settings.identity-access.authentication',
                label: 'Authentication',
                group: 'Settings',
                subgroup: 'Identity & Access',
                sort: 8,
                previousGroup: 'Users & Access',
                previousLabel: 'Authentication',
            ),
            // Note: "Users" under Identity & Access is the same canonical
            // UserResource entry registered under People above — it is
            // cross-linked from here (via crossLinkedFrom on that entry
            // in a future revision if needed), not duplicated as a second
            // navigable route.
            PasswordPolicyPage::class => new NavigationDestination(
                id: 'settings.identity-access.password-policy',
                label: 'Password Policy',
                group: 'Settings',
                subgroup: 'Identity & Access',
                sort: 9,
                previousGroup: 'Users & Access',
                previousLabel: 'Password Policy',
            ),
            RoleResource::class => new NavigationDestination(
                id: 'settings.identity-access.roles',
                label: 'Roles',
                group: 'Settings',
                subgroup: 'Identity & Access',
                sort: 10,
                previousGroup: 'Users & Access',
                previousLabel: 'Roles',
            ),
            LoginSecurityPage::class => new NavigationDestination(
                id: 'settings.identity-access.login-security',
                label: 'Login Security',
                group: 'Settings',
                subgroup: 'Identity & Access',
                sort: 11,
                previousGroup: 'Users & Access',
                previousLabel: 'Login Security',
            ),
            PermissionResource::class => new NavigationDestination(
                id: 'settings.identity-access.permissions',
                label: 'Permissions',
                group: 'Settings',
                subgroup: 'Identity & Access',
                sort: 12,
                previousGroup: 'Users & Access',
                previousLabel: 'Permissions',
            ),
            SessionPage::class => new NavigationDestination(
                id: 'settings.identity-access.session',
                label: 'Session',
                group: 'Settings',
                subgroup: 'Identity & Access',
                sort: 13,
                previousGroup: 'Users & Access',
                previousLabel: 'Session',
            ),
            RegistrationPage::class => new NavigationDestination(
                id: 'settings.identity-access.registration',
                label: 'Registration',
                group: 'Settings',
                subgroup: 'Identity & Access',
                sort: 14,
                previousGroup: 'Users & Access',
                previousLabel: 'Registration',
            ),
            AccountProtectionPage::class => new NavigationDestination(
                id: 'settings.identity-access.account-protection',
                label: 'Account Protection',
                group: 'Settings',
                subgroup: 'Identity & Access',
                sort: 15,
                previousGroup: 'Users & Access',
                previousLabel: 'Account Protection',
            ),
            // Pure configuration (thresholds, the platform-wide reviews
            // on/off switch) — distinct business function from
            // ReviewsQualityDashboard's moderation actions. Placed and
            // labeled per explicit user decision.
            ReviewQualitySettingsPage::class => new NavigationDestination(
                id: 'settings.quality.review-quality-configuration',
                label: 'Review & Quality Configuration',
                group: 'Settings',
                subgroup: 'Quality',
                sort: 16,
                previousGroup: 'Platform',
                previousLabel: 'Reviews & Quality',
            ),
            OperationalAlertResource::class => new NavigationDestination(
                id: 'settings.system-operations.operational-alerts',
                label: 'Operational Alerts',
                group: 'Settings',
                subgroup: 'System Operations',
                sort: 17,
                previousGroup: 'System',
                previousLabel: 'Operational Alerts',
            ),
            CacheManagerPage::class => new NavigationDestination(
                id: 'settings.system-operations.cache-manager',
                label: 'Cache Manager',
                group: 'Settings',
                subgroup: 'System Operations',
                sort: 18,
                previousGroup: 'System',
                previousLabel: 'Cache Manager',
            ),
            SchedulerMonitorPage::class => new NavigationDestination(
                id: 'settings.system-operations.scheduler',
                label: 'Scheduler',
                group: 'Settings',
                subgroup: 'System Operations',
                sort: 19,
                previousGroup: 'System',
                previousLabel: 'Scheduler',
            ),
            QueueMonitorPage::class => new NavigationDestination(
                id: 'settings.system-operations.queue-monitor',
                label: 'Queue Monitor',
                group: 'Settings',
                subgroup: 'System Operations',
                sort: 20,
                previousGroup: 'System',
                previousLabel: 'Queue Monitor',
            ),
            // Application Performance (Pulse link) is a plain NavigationItem
            // registered directly in AdminPanelProvider, not a Resource/Page
            // class — it has no static properties for this trait to override,
            // so its group move is applied at the ->navigationItems() call
            // site instead. Listed here only so the inventory/report account
            // for it; NavigationRegistry::destinations() is never queried for it.
            ActivityLogResource::class => new NavigationDestination(
                id: 'settings.system-operations.activity-log',
                label: 'Activity Log',
                group: 'Settings',
                subgroup: 'System Operations',
                sort: 22,
                previousGroup: 'System',
                previousLabel: 'Activity Log',
            ),
            LoginHistoryResource::class => new NavigationDestination(
                id: 'settings.system-operations.login-history',
                label: 'Login History',
                group: 'Settings',
                subgroup: 'System Operations',
                sort: 23,
                previousGroup: 'System',
                previousLabel: 'Login History',
            ),
        ];
    }

    public static function find(string $class): ?NavigationDestination
    {
        return self::destinations()[$class] ?? null;
    }

    public static function groupFor(string $class): ?string
    {
        return self::find($class)?->group;
    }

    public static function labelFor(string $class): ?string
    {
        return self::find($class)?->label;
    }

    public static function sortFor(string $class): ?int
    {
        return self::find($class)?->sort;
    }
}
