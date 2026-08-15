<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Services\DemoAvailabilityResolver;
use App\Booking\Types\FreeDemoType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\StoreStudentBookingRequest;
use App\Http\Requests\Api\Student\StudentSlotsRequest;
use App\Http\Requests\Api\Student\StudentTeachersRequest;
use App\Http\Resources\Booking\TimeSlotResource;
use App\Http\Resources\Student\StudentBookingResource;
use App\Http\Resources\Student\TeacherOptionResource;
use App\Models\User;
use App\Support\Timezone\IanaTimezone;
use App\Support\UserTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * JSON endpoints for the authenticated student booking flow.
 *
 * This controller never initiates or confirms payment: `store()` does
 * not auto-initiate a gateway payment order, and no action marks a
 * booking paid from a client-submitted `payment_reference` alone —
 * every payment is provider signature-verified.
 * Payment for a booking created here still goes through the same
 * provider-verified flow as everywhere else: `BookingWizard`/
 * `BookingHistory` (Livewire) or a verified webhook/callback.
 */
final class StudentBookingController extends Controller
{
    public function __construct(
        private readonly StudentBookingServiceInterface $studentBookings,
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

    /**
     * This endpoint calls AvailabilityServiceInterface
     * directly (no StudentBookingService/WizardBookingService in
     * between for this specific teacher+type shape), so the demo-
     * feature check must live here rather than in a service method
     * this call never goes through. Booking-type-active is already
     * guaranteed by StudentSlotsRequest's validation rule.
     */
    public function slots(StudentSlotsRequest $request, AvailabilityServiceInterface $availability, DemoAvailabilityResolver $demoAvailability): AnonymousResourceCollection
    {
        $typeKey = $request->validated('type');

        if ($typeKey === FreeDemoType::KEY && ! $demoAvailability->isAvailable()) {
            return TimeSlotResource::collection(collect());
        }

        $timezone = $this->timezoneFor($request->user(), $request->validated('timezone'));
        $date = CarbonImmutable::parse($request->validated('date'), $timezone)->startOfDay();

        return TimeSlotResource::collection($availability->slots(new AvailabilityQueryData(
            instructorId: (int) $request->validated('teacher_id'),
            typeKey: $typeKey,
            from: $date,
            to: $date->addDay(),
            timezone: $timezone,
        )));
    }

    public function store(StoreStudentBookingRequest $request): JsonResponse
    {
        $timezone = $this->timezoneFor($request->user(), $request->validated('timezone'));

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
                frequency: RecurrenceFrequency::from($request->validated('frequency')),
                interval: (int) $request->validated('interval', 1),
            ));

            return StudentBookingResource::collection($result->booked)
                ->additional([
                    'recurring_group' => $result->groupId,
                    'failures' => $result->failures,
                ])
                ->response()
                ->setStatusCode(201);
        }

        $booking = $this->studentBookings->book($data);

        return StudentBookingResource::make($booking)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * TZ-1 (TZ-AUD-013): the ONE definition of "which timezone does
     * this student's naive wall-clock input mean?", shared by slots()
     * and store() because they MUST agree. If slots were rendered in a
     * request-supplied zone but a naive `starts_at` picked from that
     * list were then interpreted in the profile zone, the student would
     * be booked at an instant they were never shown — a worse outcome
     * than either rule applied consistently.
     *
     * An explicit, valid profile timezone is the account's own answer
     * and outranks anything the request carries, so a forged or merely
     * stale `timezone` parameter cannot reinterpret a booking
     * (audit §60). The field stays accepted rather than rejected, so
     * existing clients that echo their timezone back keep working; it
     * simply stops being authoritative. A conflicting value cannot pass
     * silently either way — the resulting UTC instant is still
     * re-validated against the instructor's real availability, which
     * rejects anything that is not a genuine slot.
     *
     * The field remains meaningful for the one case where the server
     * has nothing better: an account with no explicit stored timezone.
     * There a validated request value beats falling through to the
     * Country/platform default, because the client at least knows where
     * the person is sitting.
     *
     * Note this only affects NAIVE input. A `starts_at` carrying an
     * offset or `Z` is already an absolute instant, and both PHP and
     * Carbon honour the embedded offset over any timezone argument, so
     * such a request is unambiguous no matter what this returns.
     */
    private function timezoneFor(?User $user, ?string $requested): string
    {
        if ($user === null) {
            return UserTimezoneResolver::platformDefault();
        }

        if (IanaTimezone::isValid($user->profile?->timezone)) {
            return UserTimezoneResolver::resolve($user);
        }

        return IanaTimezone::sanitize($requested) ?? UserTimezoneResolver::resolve($user);
    }
}
