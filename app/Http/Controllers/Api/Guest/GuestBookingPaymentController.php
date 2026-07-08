<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Guest;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\GuestBookingServiceInterface;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Guest\InitiateGuestBookingPaymentRequest;
use App\Http\Requests\Api\Guest\VerifyGuestBookingPaymentRequest;
use App\Settings\BookingSettings;
use Illuminate\Http\JsonResponse;

/**
 * Guest checkout for a paid booking, authorized by the same manage_token
 * every other guest endpoint uses — no session, no new credential.
 * Covers both the golden path (pay immediately after booking) and the
 * retry path (guest returns via the manage link after a failed attempt).
 */
final class GuestBookingPaymentController extends Controller
{
    public function __construct(
        private readonly GuestBookingServiceInterface $guestBookings,
        private readonly BookingPaymentServiceInterface $payments,
        private readonly RazorpayPaymentProvider $razorpay,
        private readonly BookingSettings $settings,
    ) {}

    public function initiate(InitiateGuestBookingPaymentRequest $request, string $reference): JsonResponse
    {
        $booking = $this->guestBookings->findForGuest($reference, $request->validated('token'));

        if ($this->settings->payment_provider !== 'razorpay') {
            return response()->json(['message' => 'Razorpay is not the active payment provider.'], 422);
        }

        try {
            $this->payments->initiate($booking);

            return response()->json(['data' => $this->razorpay->checkoutPayload($booking)]);
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function verify(VerifyGuestBookingPaymentRequest $request, string $reference): JsonResponse
    {
        $booking = $this->guestBookings->findForGuest($reference, $request->validated('token'));

        try {
            $payment = $this->razorpay->verifyCheckout(
                $booking,
                $request->validated('razorpay_order_id'),
                $request->validated('razorpay_payment_id'),
                $request->validated('razorpay_signature'),
            );

            // Reload before the state check: a concurrent webhook delivery may
            // have already settled this booking, and assertReference() only
            // guards against a stale-in-memory Pending, not a fresh DB read.
            $booking->refresh();

            // markPaid() wants the payment reference, not the booking reference from the URL.
            if ($booking->payment_status->isPayable()) {
                $this->payments->markPaid($booking, (string) $booking->payment_reference);
            }

            return response()->json(['status' => 'paid', 'payment_status' => $payment->status->value]);
        } catch (InvalidPaymentWebhookException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
