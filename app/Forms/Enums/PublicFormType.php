<?php

declare(strict_types=1);

namespace App\Forms\Enums;

enum PublicFormType: string
{
    case Callback = 'callback';
    case Feedback = 'feedback';
    case Support = 'support';
    case GeneralInquiry = 'general_inquiry';

    public function label(): string
    {
        return match ($this) {
            self::Callback => 'Callback Request',
            self::Feedback => 'Feedback',
            self::Support => 'Support Request',
            self::GeneralInquiry => 'General Inquiry',
        };
    }

    /** Activity Log event name — mapped in NotificationMapper for admin alerts. */
    public function logEvent(): string
    {
        return match ($this) {
            self::Callback => 'callback_requested',
            self::Feedback => 'feedback_submitted',
            self::Support => 'support_requested',
            self::GeneralInquiry => 'inquiry_submitted',
        };
    }

    public function emailSubject(): string
    {
        return match ($this) {
            self::Callback => 'New callback request',
            self::Feedback => 'New feedback submitted',
            self::Support => 'New support request',
            self::GeneralInquiry => 'New general inquiry',
        };
    }
}
