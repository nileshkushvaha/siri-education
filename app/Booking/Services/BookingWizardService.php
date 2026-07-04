<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\GuestBookingServiceInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\DTOs\GuestBookingData;
use App\Booking\DTOs\TimeSlotData;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class BookingWizardService
{
    public function __construct(
        private readonly GuestBookingServiceInterface $bookings,
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
                'price' => $type->is_paid ? $type->price : null,
                'currency' => $type->is_paid ? $type->currency : null,
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
    public function availableDates(string $typeKey, string $subject, int $grade, CarbonImmutable $from, CarbonImmutable $to, string $timezone): Collection
    {
        return $this->bookings->availableDates($typeKey, $subject, $grade, $from, $to, $timezone);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function availableSlots(string $typeKey, string $subject, int $grade, CarbonImmutable $date, string $timezone): Collection
    {
        return $this->bookings
            ->availableSlots($typeKey, $subject, $grade, $date, $timezone)
            ->map(fn (TimeSlotData $slot): array => [
                'starts_at' => $slot->startsAt->toIso8601String(),
                'ends_at' => $slot->endsAt->toIso8601String(),
                'remaining_capacity' => $slot->remainingCapacity,
            ])
            ->values();
    }

    public function book(array $data): Booking
    {
        return $this->bookings->book(new GuestBookingData(
            typeKey: $data['type'],
            subject: $data['subject'],
            grade: (int) $data['grade'],
            startsAt: CarbonImmutable::parse($data['starts_at'], $data['timezone']),
            timezone: $data['timezone'],
            guestName: $data['name'],
            guestEmail: $data['email'],
            guestPhone: $data['phone'] ?? null,
            notes: $data['notes'] ?? null,
        ));
    }

    /** @return array<string, mixed> */
    public function result(Booking $booking): array
    {
        $booking->loadMissing('type');

        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'type' => [
                'key' => $booking->type->key,
                'name' => $booking->type->name,
            ],
            'starts_at' => $booking->starts_at->timezone($booking->timezone)->toIso8601String(),
            'ends_at' => $booking->ends_at->timezone($booking->timezone)->toIso8601String(),
            'timezone' => $booking->timezone,
            'guest_name' => $booking->guest_name,
            'subject' => $booking->meta['subject'] ?? null,
            'grade' => $booking->meta['grade'] ?? null,
            'manage_token' => $booking->plainManageToken,
            'manage_url' => route('booking.manage', [
                'reference' => $booking->reference,
                'token' => $booking->plainManageToken,
            ]),
        ];
    }
}
