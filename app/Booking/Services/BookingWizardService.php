<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Types\FreeDemoType;
use App\Enums\InstructorStatus;
use App\Models\Booking;
use App\Models\User;
use App\Settings\FeatureSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class BookingWizardService
{
    public function __construct(
        private readonly WizardBookingServiceInterface $bookings,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly TeacherCandidateRepositoryInterface $teachers,
        private readonly FeatureSettings $features,
    ) {}

    /**
     * Free Demo is omitted entirely while the platform-wide feature
     * toggle is off (Phase 24B/GAP-026) — mount()'s query-param
     * preselection and selectMode() both already refuse any key absent
     * from this list, so hiding it here is sufficient to keep a stale
     * `?type=free_demo` link from reaching step 2.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function bookingTypes(): Collection
    {
        return $this->types->allActive()
            ->reject(fn ($type): bool => $type->key === FreeDemoType::KEY && ! $this->features->demo_lessons_enabled)
            ->map(fn ($type): array => [
                'key' => $type->key,
                'name' => $type->name,
                'description' => $type->description,
                'duration_minutes' => $type->duration_minutes,
                'is_paid' => $type->is_paid,
                'requires_approval' => $type->requires_approval,
            ])
            ->values();
    }

    /** @return Collection<int, string> */
    public function subjects(): Collection
    {
        return $this->teachers->availableSubjects()->values();
    }

    /** @return Collection<int, string> */
    public function availableDates(string $typeKey, string $subject, int $grade, CarbonImmutable $from, CarbonImmutable $to, string $timezone, ?int $teacherId = null): Collection
    {
        return $this->bookings->availableDates($typeKey, $subject, $grade, $from, $to, $timezone, $teacherId);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function availableSlots(string $typeKey, string $subject, int $grade, CarbonImmutable $date, string $timezone, ?int $teacherId = null): Collection
    {
        return $this->bookings
            ->availableSlots($typeKey, $subject, $grade, $date, $timezone, $teacherId)
            ->map(fn (TimeSlotData $slot): array => [
                'starts_at' => $slot->startsAt->toIso8601String(),
                'ends_at' => $slot->endsAt->toIso8601String(),
            ])
            ->values();
    }

    /** @param array<string, mixed> $data */
    public function book(array $data): Booking
    {
        return $this->bookings->book($this->wizardBookingData($data));
    }

    /** @param array<string, mixed> $data */
    public function bookRecurring(array $data, string $frequency, int $occurrences): RecurringBookingResult
    {
        return $this->bookings->bookRecurring(
            $this->wizardBookingData($data),
            new RecurrenceData($occurrences, RecurrenceFrequency::from($frequency)),
        );
    }

    /** @param array<string, mixed> $data */
    private function wizardBookingData(array $data): WizardBookingData
    {
        return new WizardBookingData(
            typeKey: $data['type'],
            subject: $data['subject'],
            grade: (int) $data['grade'],
            startsAt: CarbonImmutable::parse($data['starts_at'], $data['timezone']),
            timezone: $data['timezone'],
            notes: $data['notes'] ?? null,
            teacherId: $data['teacher_id'] ?? null,
        );
    }

    /** @return array{id:int,name:string}|null */
    public function lockedInstructor(string $slug): ?array
    {
        $instructor = User::query()
            ->where('slug', $slug)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('profile', fn ($query) => $query
                ->whereIn('instructor_status', InstructorStatus::bookableValues())
                ->where('profile_visibility', 'public'))
            ->first();

        if (! $instructor) {
            return null;
        }

        return [
            'id' => $instructor->id,
            'name' => $instructor->name,
        ];
    }

    /** @return array<string, mixed> */
    public function result(Booking $booking): array
    {
        $booking->loadMissing('type');

        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'requires_payment' => $booking->payment_status->isPayable(),
            'payment_status' => $booking->payment_status->value,
            'type' => [
                'key' => $booking->type->key,
                'name' => $booking->type->name,
            ],
            'starts_at' => $booking->starts_at->timezone($booking->timezone)->toIso8601String(),
            'ends_at' => $booking->ends_at->timezone($booking->timezone)->toIso8601String(),
            'timezone' => $booking->timezone,
            'subject' => $booking->meta['subject'] ?? null,
            'grade' => $booking->meta['grade'] ?? null,
            'my_bookings_url' => route('dashboard.my-bookings'),
        ];
    }

    /** @return array<string, mixed> */
    public function recurringResult(RecurringBookingResult $result): array
    {
        $bookings = $result->booked->map(fn (Booking $booking): array => $this->result($booking))->values();

        return [
            'recurring' => true,
            'group_id' => $result->groupId,
            'bookings' => $bookings->all(),
            'failures' => $result->failures,
            'requires_payment' => $bookings->contains(fn (array $b): bool => $b['requires_payment']),
            'my_bookings_url' => route('dashboard.my-bookings'),
        ];
    }
}
