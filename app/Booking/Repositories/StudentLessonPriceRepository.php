<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\StudentLessonPriceRepositoryInterface;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;

final class StudentLessonPriceRepository implements StudentLessonPriceRepositoryInterface
{
    public function findMatchForLevel(
        BookingType $type,
        Subject $subject,
        AcademicLevel $academicLevel,
        int $durationMinutes,
        Country $country,
        ?int $instructorId,
    ): ?StudentLessonPrice {
        return $this->baseQuery($type, $subject, $durationMinutes, $country, $instructorId)
            ->where('academic_level_id', $academicLevel->id)
            ->first();
    }

    public function findMatchForAllLevels(
        BookingType $type,
        Subject $subject,
        int $durationMinutes,
        Country $country,
        ?int $instructorId,
    ): ?StudentLessonPrice {
        return $this->baseQuery($type, $subject, $durationMinutes, $country, $instructorId)
            ->whereNull('academic_level_id')
            ->first();
    }

    private function baseQuery(BookingType $type, Subject $subject, int $durationMinutes, Country $country, ?int $instructorId): Builder
    {
        return StudentLessonPrice::query()
            ->where('booking_type_id', $type->id)
            ->where('subject_id', $subject->id)
            ->where('country_id', $country->id)
            ->where('duration_minutes', $durationMinutes)
            ->when(
                $instructorId !== null,
                fn (Builder $q) => $q->where('instructor_id', $instructorId),
                fn (Builder $q) => $q->whereNull('instructor_id'),
            )
            ->active()
            ->effectiveNow()
            // Deterministic resolution when more than one row matches: the
            // highest-priority, most-recently-made-effective row wins.
            // `id` is a random UUID (HasUuids default, not ordered), so
            // `created_at` — not `id` — is the real tie-breaker.
            ->orderByDesc('priority')
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at');
    }
}
