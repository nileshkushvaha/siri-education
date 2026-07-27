<?php

declare(strict_types=1);

namespace App\Reporting\Enums;

/**
 * Version 1 report categories (SRS §9). Each category owns a
 * coarse-grained "may this administrator see this section of the
 * reporting landing page at all" permission — individual report
 * definitions may still require a stricter, additional permission on
 * top (e.g. an instructor-compensation report inside the Finance
 * category requires `ViewInstructorCompensationReports`, not just
 * `ViewFinanceReports` — see `ReportDefinition`).
 */
enum ReportCategory: string
{
    case Executive = 'executive';
    case Operations = 'operations';
    case Students = 'students';
    case Instructors = 'instructors';
    case BookingsLessons = 'bookings_lessons';
    case Meetings = 'meetings';
    case Learning = 'learning';
    case Finance = 'finance';
    case Wallet = 'wallet';
    case Payments = 'payments';
    case EarningsSettlements = 'earnings_settlements';
    case Referrals = 'referrals';
    case Marketplace = 'marketplace';
    case ReviewsQuality = 'reviews_quality';
    case Notifications = 'notifications';

    public function label(): string
    {
        return match ($this) {
            self::Executive => 'Executive',
            self::Operations => 'Operations',
            self::Students => 'Students',
            self::Instructors => 'Instructors',
            self::BookingsLessons => 'Bookings & Lessons',
            self::Meetings => 'Meetings',
            self::Learning => 'Learning',
            self::Finance => 'Finance',
            self::Wallet => 'Wallet',
            self::Payments => 'Payments',
            self::EarningsSettlements => 'Earnings & Settlements',
            self::Referrals => 'Referrals',
            self::Marketplace => 'Marketplace',
            self::ReviewsQuality => 'Reviews & Quality',
            self::Notifications => 'Notifications',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Executive => 'Cross-domain summaries for leadership review.',
            self::Operations => 'Actionable day-to-day operational items.',
            self::Students => 'Student activity and lifecycle reporting.',
            self::Instructors => 'Instructor activity and lifecycle reporting.',
            self::BookingsLessons => 'Booking and lesson volume and outcomes.',
            self::Meetings => 'Meeting scheduling and provider reliability.',
            self::Learning => 'Homework and learning plan progress.',
            self::Finance => 'Platform financial reporting.',
            self::Wallet => 'Student wallet activity.',
            self::Payments => 'Payment collection outcomes.',
            self::EarningsSettlements => 'Instructor earnings, settlements and withdrawals.',
            self::Referrals => 'Referral program activity.',
            self::Marketplace => 'Marketplace supply and demand indicators.',
            self::ReviewsQuality => 'Review moderation, reports and quality alerts.',
            self::Notifications => 'Notification delivery reporting.',
        };
    }

    /** Heroicon name — Filament v5.6 convention (string identifier, resolved by the panel). */
    public function icon(): string
    {
        return match ($this) {
            self::Executive => 'heroicon-o-presentation-chart-line',
            self::Operations => 'heroicon-o-clipboard-document-list',
            self::Students => 'heroicon-o-academic-cap',
            self::Instructors => 'heroicon-o-user-group',
            self::BookingsLessons => 'heroicon-o-calendar-days',
            self::Meetings => 'heroicon-o-video-camera',
            self::Learning => 'heroicon-o-book-open',
            self::Finance => 'heroicon-o-banknotes',
            self::Wallet => 'heroicon-o-wallet',
            self::Payments => 'heroicon-o-credit-card',
            self::EarningsSettlements => 'heroicon-o-currency-rupee',
            self::Referrals => 'heroicon-o-gift',
            self::Marketplace => 'heroicon-o-building-storefront',
            self::ReviewsQuality => 'heroicon-o-star',
            self::Notifications => 'heroicon-o-bell',
        };
    }

    /** The coarse "may see this category at all" permission — seeded by `ReportingPermissionSeeder`. */
    public function requiredPermission(): string
    {
        return match ($this) {
            self::Executive => 'ViewExecutiveReports',
            self::Operations => 'ViewOperationalReports',
            self::Students => 'ViewStudentReports',
            self::Instructors => 'ViewInstructorReports',
            self::BookingsLessons => 'ViewBookingLessonReports',
            self::Meetings => 'ViewMeetingReports',
            self::Learning => 'ViewLearningReports',
            self::Finance => 'ViewFinanceReports',
            self::Wallet => 'ViewWalletReports',
            self::Payments => 'ViewPaymentReports',
            self::EarningsSettlements => 'ViewFinanceReports',
            self::Referrals => 'ViewReferralReports',
            self::Marketplace => 'ViewMarketplaceReports',
            self::ReviewsQuality => 'ViewReviewQualityReports',
            self::Notifications => 'ViewNotificationReports',
        };
    }

    /** Whether this category, in general, contains financial information. */
    public function isFinancial(): bool
    {
        return match ($this) {
            self::Finance, self::Wallet, self::Payments, self::EarningsSettlements => true,
            default => false,
        };
    }

    /** Whether this category, in general, contains personally-identifying information. */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::Students, self::Instructors, self::ReviewsQuality, self::EarningsSettlements => true,
            default => false,
        };
    }
}
