<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\DTOs\CreateBookingData;

/**
 * Fast-fail availability check (window + holiday + blackout + overlap
 * with buffer + daily cap). Re-checked under the host lock in
 * BookingService.
 */
final class TeacherAvailabilityRule implements BookingRuleInterface
{
    public function __construct(
        private readonly AvailabilityServiceInterface $availability,
        private readonly BookingTypeRepositoryInterface $types,
    ) {}

    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        $row = $this->types->requireActiveByKey($data->typeKey);

        $this->availability->ensureAvailable(
            $data->instructorId,
            $data->startsAt,
            $data->endsAt(),
            bufferMinutes: $row->buffer_minutes,
        );
    }
}
