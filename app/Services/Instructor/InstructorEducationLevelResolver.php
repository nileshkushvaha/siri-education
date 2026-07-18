<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Enums\EducationLevel;
use App\Enums\InstructorEducationBand;
use App\Models\User;
use App\Models\UserEducation;

/**
 * Normalizes a user's existing education data into a single
 * InstructorEducationBand, for InstructorEligibilityService to reason
 * about. Reads only existing data (UserProfile::student_academic_level_id
 * and UserEducation) — no new education table, no free-text parsing.
 *
 * Source priority:
 *  1. The student's own self-reported academic level
 *     (`user_profiles.student_academic_level_id` -> AcademicLevel), since
 *     it directly answers "what level is this person studying at right
 *     now" — the exact question the school-student restriction asks.
 *  2. The highest `UserEducation` record on file, for users who never
 *     set a student academic level (e.g. instructor-only applicants who
 *     hold no `student` role) but do have education history.
 *
 * Returns null when neither source has usable data — the caller
 * (InstructorEligibilityService) treats that as
 * MissingEducationInformation, not as an automatic pass or fail.
 */
final class InstructorEducationLevelResolver
{
    public function resolve(User $user): ?InstructorEducationBand
    {
        $fromAcademicLevel = $this->fromStudentAcademicLevel($user);

        if ($fromAcademicLevel !== null) {
            return $fromAcademicLevel;
        }

        return $this->fromEducationHistory($user);
    }

    private function fromStudentAcademicLevel(User $user): ?InstructorEducationBand
    {
        $level = $user->profile?->studentAcademicLevel;

        if ($level === null) {
            return null;
        }

        return InstructorEducationBand::tryFrom($level->slug);
    }

    private function fromEducationHistory(User $user): ?InstructorEducationBand
    {
        return $user->educations()
            ->active()
            ->get()
            ->map(fn (UserEducation $education): ?InstructorEducationBand => $this->bandForEducationLevel($education->education_level))
            ->filter()
            ->sortByDesc(fn (InstructorEducationBand $band): int => $band->rank())
            ->first();
    }

    private function bandForEducationLevel(?EducationLevel $level): ?InstructorEducationBand
    {
        return match ($level) {
            EducationLevel::School => InstructorEducationBand::HighSchool,
            EducationLevel::Diploma, EducationLevel::Bachelor => InstructorEducationBand::Undergraduate,
            EducationLevel::Master, EducationLevel::Doctorate => InstructorEducationBand::Postgraduate,
            EducationLevel::Certificate, EducationLevel::Training, EducationLevel::Bootcamp, EducationLevel::ProfessionalCertification => InstructorEducationBand::Professional,
            null => null,
        };
    }
}
