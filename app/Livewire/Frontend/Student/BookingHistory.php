<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

final class BookingHistory extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public ?Booking $selectedBooking = null;

    public bool $reschedulePanelOpen = false;

    public bool $cancelPanelOpen = false;

    public string $rescheduleDate = '';

    public ?string $rescheduleSlotStartsAt = null;

    /** @var list<array<string, mixed>> */
    public array $rescheduleSlots = [];

    public string $cancelReason = '';

    public string $modalBanner = '';

    private StudentBookingServiceInterface $bookings;

    private BookingRepositoryInterface $repository;

    private BookingServiceInterface $bookingService;

    private AvailabilityServiceInterface $availability;

    public function boot(
        StudentBookingServiceInterface $bookings,
        BookingRepositoryInterface $repository,
        BookingServiceInterface $bookingService,
        AvailabilityServiceInterface $availability,
    ): void {
        $this->bookings = $bookings;
        $this->repository = $repository;
        $this->bookingService = $bookingService;
        $this->availability = $availability;
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function viewBooking(string $bookingId): void
    {
        $booking = $this->repository->findOrFail($bookingId);

        Gate::authorize('view', $booking);

        $this->selectedBooking = $booking->loadMissing(['type', 'host']);
        $this->reschedulePanelOpen = false;
        $this->cancelPanelOpen = false;
        $this->rescheduleDate = '';
        $this->rescheduleSlotStartsAt = null;
        $this->rescheduleSlots = [];
        $this->cancelReason = '';
        $this->modalBanner = '';

        $this->dispatch('open-modal', id: 'booking-detail-modal');
    }

    public function openReschedulePanel(): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('reschedule', $this->selectedBooking);

        $this->cancelPanelOpen = false;
        $this->reschedulePanelOpen = true;
    }

    public function openCancelPanel(): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('cancel', $this->selectedBooking);

        $this->reschedulePanelOpen = false;
        $this->cancelPanelOpen = true;
    }

    public function updatedRescheduleDate(): void
    {
        $this->loadRescheduleSlots();
    }

    public function selectRescheduleSlot(string $startsAt): void
    {
        $this->rescheduleSlotStartsAt = $startsAt;
    }

    public function confirmReschedule(): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('reschedule', $this->selectedBooking);

        if (! $this->rescheduleSlotStartsAt) {
            return;
        }

        $this->modalBanner = '';

        try {
            $updated = $this->bookingService->reschedule($this->selectedBooking, new RescheduleBookingData(
                startsAt: CarbonImmutable::parse($this->rescheduleSlotStartsAt),
                actor: BookingActor::Attendee,
            ));

            $this->selectedBooking = $updated->loadMissing(['type', 'host']);
            $this->reschedulePanelOpen = false;
            $this->rescheduleDate = '';
            $this->rescheduleSlotStartsAt = null;
            $this->rescheduleSlots = [];
        } catch (BookingException $exception) {
            $this->modalBanner = $exception->getMessage();
        }
    }

    public function confirmCancel(): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('cancel', $this->selectedBooking);

        $this->modalBanner = '';

        try {
            $updated = $this->bookingService->cancel($this->selectedBooking, new CancelBookingData(
                cancelledBy: BookingActor::Attendee,
                reason: filled($this->cancelReason) ? $this->cancelReason : null,
            ));

            $this->selectedBooking = $updated->loadMissing(['type', 'host']);
            $this->cancelPanelOpen = false;
        } catch (BookingException $exception) {
            $this->modalBanner = $exception->getMessage();
        }
    }

    public function render(): View
    {
        $status = $this->statusFilter !== '' ? BookingStatus::from($this->statusFilter) : null;

        return view('livewire.frontend.student.booking-history', [
            'history' => $this->bookings->bookingHistory(auth()->user(), 10, $status),
            'statuses' => BookingStatus::cases(),
        ]);
    }

    private function loadRescheduleSlots(): void
    {
        $this->rescheduleSlotStartsAt = null;

        if (! $this->selectedBooking || ! $this->rescheduleDate) {
            $this->rescheduleSlots = [];

            return;
        }

        $timezone = $this->selectedBooking->timezone;
        $date = CarbonImmutable::parse($this->rescheduleDate, $timezone)->startOfDay();

        $this->rescheduleSlots = $this->availability->slots(new AvailabilityQueryData(
            hostId: $this->selectedBooking->host_id,
            typeKey: $this->selectedBooking->type->key,
            from: $date,
            to: $date->addDay(),
            timezone: $timezone,
        ))->map(fn ($slot): array => [
            'starts_at' => $slot->startsAt->toIso8601String(),
            'remaining_capacity' => $slot->remainingCapacity,
        ])->values()->all();
    }
}
