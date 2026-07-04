<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Guest;

use App\Booking\Contracts\GuestBookingServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Guest\GuestAvailableDatesRequest;
use App\Http\Requests\Api\Guest\GuestAvailableSlotsRequest;
use App\Http\Resources\Guest\TimeSlotResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class GuestAvailabilityController extends Controller
{
    public function __construct(
        private readonly GuestBookingServiceInterface $guestBookings,
    ) {}

    public function dates(GuestAvailableDatesRequest $request): JsonResponse
    {
        $timezone = $request->validated('timezone', 'UTC');

        $dates = $this->guestBookings->availableDates(
            typeKey: $request->validated('type'),
            subject: $request->validated('subject'),
            grade: (int) $request->validated('grade'),
            from: CarbonImmutable::parse($request->validated('from'), $timezone),
            to: CarbonImmutable::parse($request->validated('to'), $timezone)->endOfDay(),
            timezone: $timezone,
        );

        return response()->json(['data' => $dates]);
    }

    public function slots(GuestAvailableSlotsRequest $request): AnonymousResourceCollection
    {
        $timezone = $request->validated('timezone', 'UTC');

        return TimeSlotResource::collection($this->guestBookings->availableSlots(
            typeKey: $request->validated('type'),
            subject: $request->validated('subject'),
            grade: (int) $request->validated('grade'),
            date: CarbonImmutable::parse($request->validated('date'), $timezone),
            timezone: $timezone,
        ));
    }
}
