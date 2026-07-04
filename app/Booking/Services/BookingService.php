<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Actions\CancelBookingAction;
use App\Booking\Actions\CompleteBookingAction;
use App\Booking\Actions\ConfirmBookingAction;
use App\Booking\Actions\CreateBookingAction;
use App\Booking\Actions\RescheduleBookingAction;
use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Events\BookingCancelled;
use App\Booking\Events\BookingCompleted;
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingRequested;
use App\Booking\Events\BookingRescheduled;
use App\Booking\Exceptions\DuplicateBookingException;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Booking\Registry\BookingTypeRegistry;
use App\Booking\Validation\BookingValidationPipeline;
use App\Booking\Validation\Rules\BookingWindowRule;
use App\Booking\Validation\Rules\DuplicateBookingRule;
use App\Booking\Validation\Rules\TeacherAvailabilityRule;
use App\Models\Booking;
use App\Models\BookingType;
use App\Settings\BookingSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the booking lifecycle: validation pipeline → host lock →
 * atomic action + timeline entry → domain event. Model-level audit flows
 * automatically through LogsActivity → ActivityCreated → notification
 * pipeline; participant notifications hang off the domain events
 * (SendBookingNotifications, queued).
 */
final class BookingService implements BookingServiceInterface
{
    /** @var list<class-string> fast-fail rules run before the host lock */
    private const array GLOBAL_RULES = [
        BookingWindowRule::class,
        DuplicateBookingRule::class,
        TeacherAvailabilityRule::class,
    ];

    public function __construct(
        private readonly BookingTypeRegistry $registry,
        private readonly BookingValidationPipeline $pipeline,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly BookingRepositoryInterface $bookings,
        private readonly AvailabilityServiceInterface $availability,
        private readonly BookingWindowRule $window,
        private readonly BookingSettings $settings,
        private readonly CreateBookingAction $createAction,
        private readonly ConfirmBookingAction $confirmAction,
        private readonly CancelBookingAction $cancelAction,
        private readonly RescheduleBookingAction $rescheduleAction,
        private readonly CompleteBookingAction $completeAction,
    ) {}

    public function request(CreateBookingData $data): Booking
    {
        $driver = $this->registry->get($data->typeKey);
        $type = $this->types->requireActiveByKey($data->typeKey);

        $this->pipeline->run($data, $driver, self::GLOBAL_RULES);

        $booking = $this->bookings->withHostLock(
            $data->hostId,
            fn (): Booking => DB::transaction(function () use ($data, $type): Booking {
                // Race-safe re-checks: another request may have won the lock first.
                if ($this->bookings->duplicateExists($data)) {
                    throw DuplicateBookingException::for($data);
                }

                $this->availability->ensureAvailable(
                    $data->hostId,
                    $data->startsAt,
                    $data->endsAt(),
                    sharedSlotTypeKey: $this->sharedSlotKey($type),
                    bufferMinutes: $type->buffer_minutes,
                );

                $this->assertCapacity($type, $data);

                // Paid bookings are reservations: they hold the slot as
                // Pending until payment settles (BookingPaymentService
                // confirms) or the hold lapses (booking:release-expired).
                $autoConfirm = ! $type->requires_approval && ! $type->is_paid;
                $status = $autoConfirm ? BookingStatus::Confirmed : BookingStatus::Pending;

                $booking = $this->createAction->execute($data, $status, [
                    'booking_type_id' => $type->id,
                    'payment_status' => $type->is_paid ? BookingPaymentStatus::Pending : BookingPaymentStatus::NotRequired,
                    'price' => $type->is_paid ? $type->price : null,
                    'currency' => $type->is_paid ? $type->currency : null,
                    'reserved_until' => $type->is_paid
                        ? now()->addMinutes($this->settings->payment_reservation_minutes)
                        : null,
                    'confirmed_at' => $autoConfirm ? now() : null,
                    'created_by' => Auth::id(),
                ]);

                [$actorType, $actorId] = $this->actorFor($booking);

                $this->bookings->logActivity($booking, BookingActivityAction::Requested, $actorType, $actorId, null, $status);

                if ($autoConfirm) {
                    $this->bookings->logActivity($booking, BookingActivityAction::Confirmed, BookingActor::System, null, BookingStatus::Pending, BookingStatus::Confirmed, ['auto_confirmed' => true]);
                }

                return $booking;
            }),
        );

        BookingRequested::dispatch($booking);

        if ($booking->status === BookingStatus::Confirmed) {
            BookingConfirmed::dispatch($booking);
        }

        return $booking;
    }

    public function confirm(Booking $booking): Booking
    {
        $from = $booking->status;

        $booking = DB::transaction(function () use ($booking, $from): Booking {
            $booking = $this->confirmAction->execute($booking);

            [$actorType, $actorId] = $this->actorFor($booking);
            $this->bookings->logActivity($booking, BookingActivityAction::Confirmed, $actorType, $actorId, $from, BookingStatus::Confirmed);

            return $booking;
        });

        BookingConfirmed::dispatch($booking);

        return $booking;
    }

    public function reschedule(Booking $booking, RescheduleBookingData $data): Booking
    {
        $this->window->assertWithinWindow($data->startsAt);

        $previousStartsAt = $booking->starts_at;
        $previousEndsAt = $booking->ends_at;
        $duration = $data->durationMinutes ?? (int) $previousStartsAt->diffInMinutes($previousEndsAt);
        $endsAt = $data->startsAt->addMinutes($duration);

        $booking = $this->bookings->withHostLock(
            $booking->host_id,
            fn (): Booking => DB::transaction(function () use ($booking, $data, $endsAt, $previousStartsAt, $previousEndsAt): Booking {
                $this->availability->ensureAvailable(
                    $booking->host_id,
                    $data->startsAt,
                    $endsAt,
                    ignoreBookingId: $booking->id,
                    sharedSlotTypeKey: $this->sharedSlotKey($booking->type),
                    bufferMinutes: $booking->type->buffer_minutes,
                );

                $booking = $this->rescheduleAction->execute($booking, $data);

                $this->bookings->logActivity(
                    $booking,
                    BookingActivityAction::Rescheduled,
                    $data->actor,
                    Auth::id(),
                    meta: array_filter([
                        'previous_starts_at' => $previousStartsAt->toIso8601String(),
                        'previous_ends_at' => $previousEndsAt->toIso8601String(),
                        'reason' => $data->reason,
                    ]),
                );

                return $booking;
            }),
        );

        BookingRescheduled::dispatch($booking, $previousStartsAt, $previousEndsAt);

        return $booking;
    }

    public function cancel(Booking $booking, CancelBookingData $data): Booking
    {
        $from = $booking->status;

        $booking = DB::transaction(function () use ($booking, $data, $from): Booking {
            $booking = $this->cancelAction->execute($booking, $data);

            $this->bookings->logActivity(
                $booking,
                BookingActivityAction::Cancelled,
                $data->cancelledBy,
                Auth::id(),
                $from,
                BookingStatus::Cancelled,
                array_filter(['reason' => $data->reason]),
            );

            return $booking;
        });

        BookingCancelled::dispatch($booking, $data);

        return $booking;
    }

    public function complete(Booking $booking): Booking
    {
        return $this->finish($booking, BookingStatus::Completed, BookingActivityAction::Completed);
    }

    public function markNoShow(Booking $booking): Booking
    {
        return $this->finish($booking, BookingStatus::NoShow, BookingActivityAction::NoShow);
    }

    private function finish(Booking $booking, BookingStatus $outcome, BookingActivityAction $action): Booking
    {
        $from = $booking->status;

        $booking = DB::transaction(function () use ($booking, $outcome, $action, $from): Booking {
            $booking = $this->completeAction->execute($booking, $outcome);

            [$actorType, $actorId] = $this->actorFor($booking);
            $this->bookings->logActivity($booking, $action, $actorType, $actorId, $from, $outcome);

            return $booking;
        });

        BookingCompleted::dispatch($booking);

        return $booking;
    }

    /** Group types (max_attendees ≠ 1) share the exact slot. */
    private function sharedSlotKey(BookingType $type): ?string
    {
        return $type->max_attendees === 1 ? null : $type->key;
    }

    private function assertCapacity(BookingType $type, CreateBookingData $data): void
    {
        if ($type->max_attendees === null || $type->max_attendees === 1) {
            return; // uncapped, or covered by the overlap check
        }

        $taken = $this->bookings->attendeeCountForSlot($data->hostId, $type->key, $data->startsAt);

        if ($taken >= $type->max_attendees) {
            throw SlotUnavailableException::for($data->hostId, $data->startsAt);
        }
    }

    /** @return array{BookingActor, ?int} */
    private function actorFor(Booking $booking): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [BookingActor::System, null];
        }

        return [
            match ($user->id) {
                $booking->host_id => BookingActor::Host,
                $booking->attendee_id => BookingActor::Attendee,
                default => BookingActor::Admin,
            },
            $user->id,
        ];
    }
}
