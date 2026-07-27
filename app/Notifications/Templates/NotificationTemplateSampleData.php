<?php

declare(strict_types=1);

namespace App\Notifications\Templates;

/**
 * Preview with safe sample data — fixed,
 * entirely fictional values keyed by template. Never reads a real
 * record, so a preview can never leak an actual student/instructor
 * name, amount, or reference.
 */
final class NotificationTemplateSampleData
{
    /** @return array<string, string> */
    public static function for(NotificationTemplateKey $key): array
    {
        return match ($key) {
            NotificationTemplateKey::BookingConfirmed => [
                'booking_type' => 'Paid 1:1 Lesson',
                'lesson_datetime' => 'Mon, Jan 6 2026 at 14:00',
                'timezone' => 'Asia/Kolkata',
                'booking_reference' => 'BK-000000',
            ],
            NotificationTemplateKey::WalletRechargeSucceeded => [
                'amount' => '$50.00',
                'reference' => 'RCHG-000000',
            ],
            NotificationTemplateKey::HomeworkDueReminder => [
                'homework_title' => 'Sample Assignment',
                'due_wording' => 'in 2 hours',
                'due_date' => 'Mon, Jan 6 2026 at 6:00 PM',
                'context_label' => 'From your recent lesson.',
            ],
            NotificationTemplateKey::MessageReceived => [
                'sender_name' => 'Jane Doe',
            ],
            NotificationTemplateKey::SupportCaseCreated => [
                'case_subject' => 'Sample support request',
                'case_number' => 'SUP-2026-000000',
            ],
            NotificationTemplateKey::SuspiciousActivityFlagged => [
                'severity' => 'Warning',
                'reference' => 'FLAG-000000',
                'rule_name' => 'Sample Rule',
            ],
            NotificationTemplateKey::ReferralRewardCredited => [
                'amount' => '$10.00',
                'referred_name' => 'J. Smith',
            ],
            NotificationTemplateKey::PromotionalCreditIssued => [
                'amount' => '$25.00',
                'campaign_line' => 'Campaign: Sample Campaign',
            ],
        };
    }
}
