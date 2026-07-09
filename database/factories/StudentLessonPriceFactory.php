<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentLessonPrice>
 *
 * `subject_id` (and `academic_level_id`, when needed) have no factory
 * default — Subject/AcademicLevel have no factories in this codebase
 * (see SubjectTeacherSubjectReconciliationTest for the `::create()`
 * convention) — pass them explicitly, e.g.
 * `StudentLessonPrice::factory()->create(['subject_id' => $subject->id])`.
 */
class StudentLessonPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_type_id' => BookingType::factory()->paid(),
            'instructor_id' => null,
            'academic_level_id' => null,
            'country_id' => Country::factory(),
            'currency_id' => Currency::factory(),
            'currency_code' => fn (array $attributes): string => Currency::query()->find($attributes['currency_id'])?->code ?? 'USD',
            'duration_minutes' => 60,
            'amount_minor' => 49900,
            'is_active' => true,
            'effective_from' => null,
            'effective_until' => null,
            'priority' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function forLevel(string $academicLevelId): static
    {
        return $this->state(['academic_level_id' => $academicLevelId]);
    }

    /** Instructor-specific override — null (the default) means the base price, applied to every instructor. */
    public function forInstructor(int $instructorId): static
    {
        return $this->state(['instructor_id' => $instructorId]);
    }
}
