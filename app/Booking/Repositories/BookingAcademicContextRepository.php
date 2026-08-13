<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\BookingAcademicContextRepositoryInterface;
use App\Booking\DTOs\BookingAcademicContextData;
use App\Models\Booking;
use App\Models\BookingAcademicContext;

final class BookingAcademicContextRepository implements BookingAcademicContextRepositoryInterface
{
    public function createFor(Booking $booking, BookingAcademicContextData $context): BookingAcademicContext
    {
        // firstOrCreate on the unique booking_id — a retried/duplicate
        // call (e.g. an action re-run) returns the existing snapshot
        // rather than violating the unique constraint or creating a
        // second row.
        return BookingAcademicContext::query()->firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'country_id' => $context->countryId,
                'country_code' => $context->countryCode,
                'country_name' => $context->countryName,
                'education_system_id' => $context->educationSystemId,
                'education_system_code' => $context->educationSystemCode,
                'education_system_name' => $context->educationSystemName,
                'academic_level_id' => $context->academicLevelId,
                'academic_level_name' => $context->academicLevelName,
                'education_system_level_id' => $context->educationSystemLevelId,
                'level_term' => $context->levelTerm,
                'level_value' => $context->levelValue,
                'level_display' => $context->levelDisplay,
                'normalized_grade' => $context->normalizedGrade,
                'subject_id' => $context->subjectId,
                'subject_name' => $context->subjectName,
                'subject_slug' => $context->subjectSlug,
                'curriculum_id' => $context->curriculumId,
                'curriculum_name' => $context->curriculumName,
                'curriculum_slug' => $context->curriculumSlug,
                'curriculum_version_id' => $context->curriculumVersionId,
                'curriculum_version_number' => $context->curriculumVersionNumber,
            ],
        );
    }
}
