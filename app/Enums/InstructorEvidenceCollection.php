<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Media collections supported by the instructor verification and profile
 * evidence workflow. Values must match UserProfile media collections.
 */
enum InstructorEvidenceCollection: string
{
    case GovernmentId = 'government_id';
    case AddressProof = 'address_proof';
    case EducationCertificate = 'education_certificate';
    case TeachingCertificate = 'teaching_certificate';
    case Resume = 'resume';
    case IntroductionVideo = 'introduction_video';

    public function label(): string
    {
        return match ($this) {
            self::GovernmentId => 'Government ID',
            self::AddressProof => 'Address Proof',
            self::EducationCertificate => 'Education Certificate',
            self::TeachingCertificate => 'Teaching Certificate',
            self::Resume => 'Resume',
            self::IntroductionVideo => 'Introduction Video',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $collection): array => [$collection->value => $collection->label()])
            ->all();
    }
}
