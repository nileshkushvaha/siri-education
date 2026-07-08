<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BookingPaymentWebhookController;
use App\Http\Controllers\Api\Guest\GuestAvailabilityController;
use App\Http\Controllers\Api\Guest\GuestBookingController;
use App\Http\Controllers\Api\Guest\GuestBookingPaymentController;
use App\Http\Controllers\Api\Guest\GuestCatalogController;
use App\Http\Controllers\Payments\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/payments/{gateway}', PaymentWebhookController::class)
    ->name('api.payments.webhooks.handle');

// Booking payment provider notifications (signature-verified per provider).
Route::post('/webhooks/bookings/payments/{provider}', BookingPaymentWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.bookings.payments.webhook');

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
        Route::post('/bookings', [GuestBookingController::class, 'store'])->name('bookings.store');
        Route::post('/bookings/{reference}/cancel', [GuestBookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('/bookings/{reference}/reschedule', [GuestBookingController::class, 'reschedule'])->name('bookings.reschedule');
        Route::post('/bookings/{reference}/payments/razorpay/initiate', [GuestBookingPaymentController::class, 'initiate'])->name('bookings.payments.initiate');
        Route::post('/bookings/{reference}/payments/razorpay/verify', [GuestBookingPaymentController::class, 'verify'])->name('bookings.payments.verify');
    });
});
