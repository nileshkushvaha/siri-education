<?php

declare(strict_types=1);

namespace App\Messaging\Enums;

/** SRS §17.35. */
enum MessageReportReason: string
{
    case OffPlatformSolicitation = 'off_platform_solicitation';
    case AbuseOrHarassment = 'abuse_or_harassment';
    case Spam = 'spam';
    case InappropriateContent = 'inappropriate_content';
    case PaymentRequest = 'payment_request';
    case ContactSharing = 'contact_sharing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OffPlatformSolicitation => 'Off-Platform Solicitation',
            self::AbuseOrHarassment => 'Abuse or Harassment',
            self::Spam => 'Spam',
            self::InappropriateContent => 'Inappropriate Content',
            self::PaymentRequest => 'Payment Request',
            self::ContactSharing => 'Contact Sharing',
            self::Other => 'Other',
        };
    }
}
