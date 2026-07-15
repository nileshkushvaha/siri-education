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
use App\Booking\Validation\Rules\SelfBookingRule;
use App\Booking\Validation\Rules\TeacherAvailabilityRule;
use App\Booking\Validation\Rules\VerifiedActiveStudentRule;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
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
        // Identity/eligibility checks first — fail with the clearest,
        // most specific message before any scheduling/pricing rule runs.
        // Billing-profile completeness (country/currency) is checked at
        // payment-initiation time instead (BookingPaymentService::initiate()),
        // not here — a student may still create/hold a paid reservation
        // with an incomplete profile, but is asked to complete it before
        // paying, which is both the natural UX point and a much narrower
        // blast radius than blocking booking creation outright.
        VerifiedActiveStudentRule::class,
        BookingWindowRule::class,
        SelfBookingRule::class,
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
        private readonly BookingPriceCalculator $priceCalculator,
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

        $booking = $this->bookings->withInstructorLock(
            $data->instructorId,
            fn (): Booking => DB::transaction(function () use ($data, $type): Booking {
                // Race-safe re-checks: another request may have won the lock first.
                if ($this->bookings->duplicateExists($data)) {
                    throw DuplicateBookingException::for($data);
                }

                $this->availability->ensureAvailable(
                    $data->instructorId,
                    $data->startsAt,
                    $data->endsAt(),
                    sharedSlotTypeKey: $this->sharedSlotKey($type),
                    bufferMinutes: $type->buffer_minutes,
                );

                $this->assertCapacity($type, $data);

                // Paid bookings are reservations: they hold the slot as
                // Pending until payment settles (BookingPaymentService
                // confirms) or the hold lapses (booking:release-expired).
                // A paid type with no valid price configured anywhere
                // (pricing matrix miss AND no legacy price/currency) is a
                // configuration error, not a free booking — calculate()
                // throws rather than silently waiving the fee (Phase
                // 10.2C-Fix). subject/grade (Phase 10.2D) come from
                // $data->meta the same way StudentBookingResource reads
                // them back — see CreateBookingData's meta doc comment.
                // $data->instructorId (Phase 10.2F) lets an instructor-specific
                // price override the base price when one is configured.
                $price = $this->priceCalculator->calculate(
                    $type,
                    $this->attendeeFor($data),
                    $data->meta['subject'] ?? null,
                    $data->meta['grade'] ?? null,
                    $data->instructorId,
                );
                $autoConfirm = ! $type->requires_approval && $price->isFreeBooking;
                $status = $autoConfirm ? BookingStatus::Confirmed : BookingStatus::Pending;

                $booking = $this->createAction->execute($data, $status, [
                    'booking_type_id' => $type->id,
                    'payment_status' => $price->requiresPayment ? BookingPaymentStatus::Pending : BookingPaymentStatus::NotRequired,
                    'price' => $price->requiresPayment ? $price->payableAmount : null,
                    'currency' => $price->requiresPayment ? $price->currency : null,
                    'reserved_until' => $price->requiresPayment
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

        $booking = $this->bookings->withInstructorLock(
            $booking->instructor_id,
            fn (): Booking => DB::transaction(function () use ($booking, $data, $endsAt, $previousStartsAt, $previousEndsAt): Booking {
                $this->availability->ensureAvailable(
                    $booking->instructor_id,
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

    /** Only used for currency display fallback — never for authorization. */
    private function attendeeFor(CreateBookingData $data): ?User
    {
        return $data->studentId !== null ? User::find($data->studentId) : null;
    }

    private function assertCapacity(BookingType $type, CreateBookingData $data): void
    {
        if ($type->max_attendees === null || $type->max_attendees === 1) {
            return; // uncapped, or covered by the overlap check
        }

        $taken = $this->bookings->studentCountForSlot($data->instructorId, $type->key, $data->startsAt);

        if ($taken >= $type->max_attendees) {
            throw SlotUnavailableException::for($data->instructorId, $data->startsAt);
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
                $booking->instructor_id => BookingActor::Instructor,
                $booking->student_id => BookingActor::Student,
                default => BookingActor::Admin,
            },
            $user->id,
        ];
    }
}
