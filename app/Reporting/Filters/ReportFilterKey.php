<?php

declare(strict_types=1);

namespace App\Reporting\Filters;

/**
 * Stable identifiers for the optional filter dimensions a report
 * definition may declare support for (Phase 18B §8). `period` and its
 * timezone are always present on `ReportFilters` — they are core, not
 * optional, so they have no key here.
 *
 * Referral status and notification channel/status are deliberately
 * absent: no authoritative Referral or Notification-status enum exists
 * anywhere in the codebase yet (Phase 18B discovery), so no filter
 * dimension is defined for them — adding one now would invent a new
 * source-domain status ahead of its owning domain existing.
 */
enum ReportFilterKey: string
{
    case Country = 'country';
    case Currency = 'currency';
    case Subject = 'subject';
    case EducationLevel = 'education_level';
    case Student = 'student';
    case Instructor = 'instructor';
    case BookingType = 'booking_type';
    case RecurrenceType = 'recurrence_type';
    case BookingStatus = 'booking_status';
    case LessonStatus = 'lesson_status';
    case LessonOutcome = 'lesson_outcome';
    case MeetingStatus = 'meeting_status';
    case PaymentStatus = 'payment_status';
    case WalletTransactionType = 'wallet_transaction_type';
    case WalletTransactionStatus = 'wallet_transaction_status';
    case EarningStatus = 'earning_status';
    case SettlementStatus = 'settlement_status';
    case WithdrawalStatus = 'withdrawal_status';
    case ReviewStatus = 'review_status';
    case ReviewReportStatus = 'review_report_status';
    case QualityAlertStatus = 'quality_alert_status';
}
