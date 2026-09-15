<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentJoinState;
use App\Models\Booking;
use App\Models\User;
use App\Support\UserTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The student's schedule as every portal surface renders it: each
 * current-or-upcoming lesson paired with its authoritative join state,
 * so the dashboard, My Bookings and Upcoming Classes all show the same
 * join button at the same instant. Read-only; one lifecycle read per
 * call regardless of how many lessons are listed.
 */
final class StudentScheduleService
{
    public function __construct(
        private readonly StudentBookingServiceInterface $bookings,
        private readonly BookingMeetingServiceInterface $meetings,
    ) {}

    /**
     * @return Collection<int, array{booking: Booking, join: StudentJoinState, today: bool}>
     */
    public function upcoming(User $student, ?int $limit = null): Collection
    {
        $bookings = $this->bookings->upcomingClasses($student, $limit);
        $states = $this->meetings->studentJoinStatesFor($bookings, $student);

        // "Today" is the student's own calendar day, not the server's: a
        // lesson in progress that started before local midnight is still
        // today, and a lesson after local midnight is not.
        $timezone = UserTimezoneResolver::resolve($student);
        $endOfToday = CarbonImmutable::now($timezone)->endOfDay();

        return $bookings->map(fn (Booking $booking): array => [
            'booking' => $booking,
            'join' => $states[$booking->getKey()] ?? StudentJoinState::unavailable($booking->hasEnded()),
            'today' => $booking->starts_at !== null
                && CarbonImmutable::parse($booking->starts_at)->setTimezone($timezone)->lte($endOfToday),
        ])->values();
    }

    /** @return array{booking: Booking, join: StudentJoinState, today: bool}|null the soonest lesson not yet ended */
    public function nextUp(User $student): ?array
    {
        return $this->upcoming($student, 1)->first();
    }

    /**
     * Whether any listed lesson's join state can change on its own soon
     * — the parent component polls only then, never all day.
     *
     * @param  Collection<int, array{booking: Booking, join: StudentJoinState, today: bool}>  $rows
     */
    public function poll(Collection $rows): bool
    {
        return $rows->contains(fn (array $row): bool => $row['join']->poll);
    }
}
