<?php

declare(strict_types=1);

namespace App\Reviews\Enums;

/** Why a student/instructor is reporting a published public review. */
enum ReviewReportReason: string
{
    case FakeOrMisleading = 'fake_or_misleading';
    case AbusiveLanguage = 'abusive_language';
    case PersonalInformation = 'personal_information';
    case OffPlatformSolicitation = 'off_platform_solicitation';
    case HateOrHarassment = 'hate_or_harassment';
    case Spam = 'spam';
    case IrrelevantContent = 'irrelevant_content';
    case PrivacyConcern = 'privacy_concern';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FakeOrMisleading => 'Fake or Misleading',
            self::AbusiveLanguage => 'Abusive Language',
            self::PersonalInformation => 'Personal Information',
            self::OffPlatformSolicitation => 'Off-Platform Solicitation',
            self::HateOrHarassment => 'Hate or Harassment',
            self::Spam => 'Spam',
            self::IrrelevantContent => 'Irrelevant Content',
            self::PrivacyConcern => 'Privacy Concern',
            self::Other => 'Other',
        };
    }
}
