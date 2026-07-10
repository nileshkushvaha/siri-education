<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * An authenticated student's booking request. Unlike guests, students
 * choose their teacher; duration still comes from the booking type.
 */
final readonly class StudentBookingData
{
    public function __construct(
        public string $typeKey,
        public int $studentId,
        public int $teacherId,
        public CarbonImmutable $startsAt,
        public string $timezone = 'UTC',
        public ?string $subject = null,
        public ?int $grade = null,
        public ?string $notes = null,
        /** Optional SubjectTopic slug under $subject (Phase 12.5); requires explicit instructor topic coverage. */
        public ?string $topic = null,
    ) {}
}
