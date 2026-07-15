<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\DTOs\WizardBookingData;
use App\Enums\InstructorStatus;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class BookingWizardService
{
    public function __construct(
        private readonly WizardBookingServiceInterface $bookings,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly TeacherCandidateRepositoryInterface $teachers,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function bookingTypes(): Collection
    {
        return $this->types->allActive()
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
                'remaining_capacity' => $slot->remainingCapacity,
            ])
            ->values();
    }

    /** @param array<string, mixed> $data */
    public function book(array $data): Booking
    {
        return $this->bookings->book(new WizardBookingData(
            typeKey: $data['type'],
            subject: $data['subject'],
            grade: (int) $data['grade'],
            startsAt: CarbonImmutable::parse($data['starts_at'], $data['timezone']),
            timezone: $data['timezone'],
            notes: $data['notes'] ?? null,
            teacherId: $data['teacher_id'] ?? null,
        ));
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
}
