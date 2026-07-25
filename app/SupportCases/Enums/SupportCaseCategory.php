<?php

declare(strict_types=1);

namespace App\SupportCases\Enums;

/**
 * SRS §25.7. CMS/Public Website is omitted — that category routes to
 * the existing public contact form, not an authenticated case here.
 */
enum SupportCaseCategory: string
{
    case Account = 'account';
    case StudentProfile = 'student_profile';
    case InstructorProfile = 'instructor_profile';
    case InstructorVerification = 'instructor_verification';
    case Booking = 'booking';
    case DemoLesson = 'demo_lesson';
    case PaidLesson = 'paid_lesson';
    case RecurringLesson = 'recurring_lesson';
    case Cancellation = 'cancellation';
    case Reschedule = 'reschedule';
    case NoShow = 'no_show';
    case Meeting = 'meeting';
    case Recording = 'recording';
    case Payment = 'payment';
    case Wallet = 'wallet';
    case Refund = 'refund';
    case InstructorEarnings = 'instructor_earnings';
    case Withdrawal = 'withdrawal';
    case Referral = 'referral';
    case Review = 'review';
    case Homework = 'homework';
    case TechnicalIssue = 'technical_issue';
    case PolicyViolation = 'policy_violation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Account',
            self::StudentProfile => 'Student Profile',
            self::InstructorProfile => 'Instructor Profile',
            self::InstructorVerification => 'Instructor Verification',
            self::Booking => 'Booking',
            self::DemoLesson => 'Demo Lesson',
            self::PaidLesson => 'Paid Lesson',
            self::RecurringLesson => 'Recurring Lesson',
            self::Cancellation => 'Cancellation',
            self::Reschedule => 'Reschedule',
            self::NoShow => 'No-Show',
            self::Meeting => 'Meeting / Virtual Classroom',
            self::Recording => 'Recording',
            self::Payment => 'Payment',
            self::Wallet => 'Wallet',
            self::Refund => 'Refund',
            self::InstructorEarnings => 'Instructor Earnings',
            self::Withdrawal => 'Withdrawal',
            self::Referral => 'Referral / Reward',
            self::Review => 'Review / Rating',
            self::Homework => 'Homework / Resources',
            self::TechnicalIssue => 'Technical Issue',
            self::PolicyViolation => 'Policy Violation',
            self::Other => 'Other',
        };
    }
}
