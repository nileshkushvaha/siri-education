<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\StudentLessonPrice;
use App\Models\Subject;

interface StudentLessonPriceRepositoryInterface
{
    /**
     * Highest-priority active, currently-effective row matching the
     * given academic level exactly, scoped to `$instructorId` (or, when
     * null, to the base price — `instructor_id IS NULL`). Callers try
     * this first, then fall back to {@see findMatchForAllLevels()}.
     */
    public function findMatchForLevel(
        BookingType $type,
        Subject $subject,
        AcademicLevel $academicLevel,
        int $durationMinutes,
        Country $country,
        ?int $instructorId,
    ): ?StudentLessonPrice;

    /** Same match, but for a row explicitly configured to apply to every academic level (`academic_level_id IS NULL`). */
    public function findMatchForAllLevels(
        BookingType $type,
        Subject $subject,
        int $durationMinutes,
        Country $country,
        ?int $instructorId,
    ): ?StudentLessonPrice;
}
