<?php

declare(strict_types=1);

namespace App\Reviews\Enums;

/**
 * A category of unsafe/contact-leaking content the sanitizer detected
 * in submitted review text. Stored (as values only) in
 * lesson_reviews.sanitization_metadata — never alongside the raw
 * matched text.
 */
enum ReviewContentFlag: string
{
    case Email = 'email';
    case PhoneNumber = 'phone_number';
    case ExternalLink = 'external_link';
    case SocialHandle = 'social_handle';
    case PaymentSolicitation = 'payment_solicitation';
    case PromotionalSpam = 'promotional_spam';
    case UnsupportedHtml = 'unsupported_html';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email Address',
            self::PhoneNumber => 'Phone Number',
            self::ExternalLink => 'External Link',
            self::SocialHandle => 'Social Media Handle',
            self::PaymentSolicitation => 'Payment Solicitation',
            self::PromotionalSpam => 'Promotional Spam',
            self::UnsupportedHtml => 'Unsupported HTML/Script',
        };
    }
}
