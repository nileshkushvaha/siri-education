<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\StoreStudentBookingRequest;
use App\Http\Requests\Api\Student\StudentSlotsRequest;
use App\Http\Requests\Api\Student\StudentTeachersRequest;
use App\Http\Resources\Guest\TimeSlotResource;
use App\Http\Resources\Student\StudentBookingResource;
use App\Http\Resources\Student\TeacherOptionResource;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** JSON endpoints for the authenticated student booking flow. */
final class StudentBookingController extends Controller
{
    public function __construct(
        private readonly StudentBookingServiceInterface $studentBookings,
        private readonly BookingPaymentServiceInterface $payments,
    ) {}

    public function index(Request $request, BookingRepositoryInterface $bookings): AnonymousResourceCollection
    {
        return StudentBookingResource::collection(
            $bookings->upcomingForUser($request->user()->id),
        );
    }

    public function teachers(StudentTeachersRequest $request): AnonymousResourceCollection
    {
        return TeacherOptionResource::collection($this->studentBookings->availableTeachers(
            $request->validated('type'),
            $request->validated('subject'),
            (int) $request->validated('grade'),
        ));
    }

    public function previousTeachers(Request $request): AnonymousResourceCollection
    {
        return TeacherOptionResource::collection(
            $this->studentBookings->previousTeachers($request->user()),
        );
    }

    public function slots(StudentSlotsRequest $request, AvailabilityServiceInterface $availability): AnonymousResourceCollection
    {
        $timezone = $request->validated('timezone', 'UTC');
        $date = CarbonImmutable::parse($request->validated('date'), $timezone)->startOfDay();

        return TimeSlotResource::collection($availability->slots(new AvailabilityQueryData(
            hostId: (int) $request->validated('teacher_id'),
            typeKey: $request->validated('type'),
            from: $date,
            to: $date->addDay(),
            timezone: $timezone,
        )));
    }

    public function store(StoreStudentBookingRequest $request): JsonResponse
    {
        $timezone = $request->validated('timezone', 'UTC');

        $data = new StudentBookingData(
            typeKey: $request->validated('type'),
            studentId: $request->user()->id,
            teacherId: (int) $request->validated('teacher_id'),
            startsAt: CarbonImmutable::parse($request->validated('starts_at'), $timezone)->utc(),
            timezone: $timezone,
            subject: $request->validated('subject'),
            grade: $request->validated('grade') !== null ? (int) $request->validated('grade') : null,
            notes: $request->validated('notes'),
        );

        if ($request->boolean('recurring')) {
            $result = $this->studentBookings->bookRecurring($data, new RecurrenceData(
                occurrences: (int) $request->validated('occurrences'),
                intervalWeeks: (int) $request->validated('interval_weeks', 1),
            ));

            return StudentBookingResource::collection($result->booked)
                ->additional([
                    'recurring_group' => $result->groupId,
                    'failures' => $result->failures,
                    'payment' => $this->paymentIntentFor($result->booked->first()),
                ])
                ->response()
                ->setStatusCode(201);
        }

        $booking = $this->studentBookings->book($data);

        return StudentBookingResource::make($booking)
            ->additional(['payment' => $this->paymentIntentFor($booking)])
            ->response()
            ->setStatusCode(201);
    }

    public function pay(Request $request, Booking $booking): StudentBookingResource
    {
        Gate::authorize('pay', $booking);

        $request->validate(['reference' => ['required', 'string', 'max:100']]);

        return StudentBookingResource::make(
            $this->payments->markPaid($booking, $request->string('reference')->toString()),
        );
    }

    /** @return array<string, mixed>|null */
    private function paymentIntentFor(?Booking $booking): ?array
    {
        if ($booking === null || $booking->payment_status !== BookingPaymentStatus::Pending) {
            return null;
        }

        return (array) $this->payments->initiate($booking);
    }
}
