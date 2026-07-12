<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BookingPaymentWebhookController;
use App\Http\Controllers\Api\Guest\GuestAvailabilityController;
use App\Http\Controllers\Api\Guest\GuestBookingController;
use App\Http\Controllers\Api\Guest\GuestCatalogController;
use App\Http\Controllers\Api\MeetingAttendanceWebhookController;
use App\Http\Controllers\Api\RazorpayXPayoutWebhookController;
use App\Http\Controllers\Payments\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

// Generic gateway webhook — logs/audits only, never settles a booking
// payment (see PaymentWebhookProcessor's docblock). Deliberately
// isolated under /generic/ and its own route name so it can never be
// mistaken for the real booking-settlement route below.
Route::post('/webhooks/payments/generic/{gateway}', PaymentWebhookController::class)
    ->name('api.payments.webhooks.generic');

// Booking payment provider notifications (signature-verified per provider).
Route::post('/webhooks/bookings/payments/{provider}', BookingPaymentWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.bookings.payments.webhook');

// Meeting attendance provider notifications (Phase 17C) — signature
// verified before parsing; gated by meeting.attendance_webhooks_enabled;
// evidence only, never outcome or booking mutations.
Route::post('/webhooks/meetings/attendance/{provider}', MeetingAttendanceWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.meetings.attendance.webhook');

// RazorpayX instructor payout provider notifications (Phase 16B) — a
// separate financial domain from booking payments; never shares a
// route, controller, or rate limiter with the collection side.
Route::post('/webhooks/payouts/razorpayx', RazorpayXPayoutWebhookController::class)
    ->middleware('throttle:razorpayx-payout-webhook')
    ->name('api.payouts.razorpayx.webhook');

// Public guest booking API — no authentication; manage_token authorizes
// per-booking actions, named rate limiters throttle by IP.
Route::prefix('v1/guest')->name('api.guest.')->group(function (): void {
    Route::middleware('throttle:guest-availability')->group(function (): void {
        Route::get('/booking-types', [GuestCatalogController::class, 'types'])->name('booking-types.index');
        Route::get('/subjects', [GuestCatalogController::class, 'subjects'])->name('subjects.index');
        Route::get('/availability/dates', [GuestAvailabilityController::class, 'dates'])->name('availability.dates');
        Route::get('/availability/slots', [GuestAvailabilityController::class, 'slots'])->name('availability.slots');
        Route::get('/bookings/{reference}', [GuestBookingController::class, 'show'])->name('bookings.show');
    });

    Route::middleware('throttle:guest-booking-write')->group(function (): void {
        // Phase 10.2C-Fix: store() itself now always rejects (see
        // GuestBookingController::store() / AuthenticatedAttendeeRule) —
        // kept mounted only so it returns a clean 422 instead of a 404,
        // matching every other "no guest booking" surface.
        Route::post('/bookings', [GuestBookingController::class, 'store'])->name('bookings.store');
        Route::post('/bookings/{reference}/cancel', [GuestBookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('/bookings/{reference}/reschedule', [GuestBookingController::class, 'reschedule'])->name('bookings.reschedule');
    });

    // Phase 10.2C-Fix: guest payment is disabled outright ("No
    // unauthenticated user may initiate payment" / "No guest payment
    // UI") — GuestBookingPaymentController is kept for reference but is
    // deliberately unrouted, not reachable from any public route. A
    // guest booking that already has a Pending payment_status (legacy
    // data only — new ones can no longer be created) has no path to pay
    // through this API; it is a manual/admin resolution case, same as a
    // late-arriving webhook after cancellation (Phase 10.2B Option B).
});
