<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Normalized education band used only for instructor-eligibility
 * decisions. Values are deliberately the same slugs as the seeded
 * AcademicLevel master-data rows (see AcademicLevelSeeder) so a
 * student's own `student_academic_level_id` selection maps onto this
 * enum without a second, parallel taxonomy — see
 * InstructorEducationLevelResolver.
 */
enum InstructorEducationBand: string
{
    case Primary = 'primary';
    case MiddleSchool = 'middle-school';
    case HighSchool = 'high-school';
    case Undergraduate = 'undergraduate';
    case Postgraduate = 'postgraduate';
    case Professional = 'professional';

    /** Pre-higher-education bands — the only ones instructor eligibility restricts. */
    public function isSchoolTier(): bool
    {
        return in_array($this, [self::Primary, self::MiddleSchool, self::HighSchool], true);
    }

    /** Relative seniority, used only to pick the highest band across multiple education records. */
    public function rank(): int
    {
        return match ($this) {
            self::Primary => 0,
            self::MiddleSchool => 1,
            self::HighSchool => 2,
            self::Undergraduate => 3,
            self::Postgraduate => 4,
            self::Professional => 5,
        };
    }
}
