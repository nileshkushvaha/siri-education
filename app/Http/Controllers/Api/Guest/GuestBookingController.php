<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Guest;

use App\Booking\Contracts\GuestBookingServiceInterface;
use App\Booking\DTOs\GuestBookingData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Guest\CancelGuestBookingRequest;
use App\Http\Requests\Api\Guest\RescheduleGuestBookingRequest;
use App\Http\Requests\Api\Guest\ShowGuestBookingRequest;
use App\Http\Requests\Api\Guest\StoreGuestBookingRequest;
use App\Http\Resources\Guest\GuestBookingResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class GuestBookingController extends Controller
{
    public function __construct(
        private readonly GuestBookingServiceInterface $guestBookings,
    ) {}

    public function store(StoreGuestBookingRequest $request): JsonResponse
    {
        $timezone = $request->validated('timezone', 'UTC');

        $booking = $this->guestBookings->book(new GuestBookingData(
            typeKey: $request->validated('type'),
            subject: $request->validated('subject'),
            grade: (int) $request->validated('grade'),
            startsAt: CarbonImmutable::parse($request->validated('starts_at'), $timezone)->utc(),
            timezone: $timezone,
            guestName: $request->validated('name'),
            guestEmail: strtolower(trim($request->validated('email'))),
            guestPhone: $request->validated('phone'),
            notes: $request->validated('notes'),
        ));

        return GuestBookingResource::make($booking)
            ->additional([
                // Returned exactly once — the guest's only credential to
                // view, cancel, or reschedule. Only its hash is stored.
                'manage_token' => $booking->plainManageToken,
                'manage_url' => route('booking.manage', [
                    'reference' => $booking->reference,
                    'token' => $booking->plainManageToken,
                ]),
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function show(ShowGuestBookingRequest $request, string $reference): GuestBookingResource
    {
        return GuestBookingResource::make(
            $this->guestBookings->findForGuest($reference, $request->validated('token')),
        );
    }

    public function cancel(CancelGuestBookingRequest $request, string $reference): GuestBookingResource
    {
        $booking = $this->guestBookings->findForGuest($reference, $request->validated('token'));

        return GuestBookingResource::make(
            $this->guestBookings->cancel($booking, $request->validated('reason')),
        );
    }

    public function reschedule(RescheduleGuestBookingRequest $request, string $reference): GuestBookingResource
    {
        $booking = $this->guestBookings->findForGuest($reference, $request->validated('token'));
        $timezone = $request->validated('timezone', $booking->timezone);

        return GuestBookingResource::make($this->guestBookings->reschedule(
            $booking,
            CarbonImmutable::parse($request->validated('starts_at'), $timezone)->utc(),
            $request->validated('reason'),
        ));
    }
}
