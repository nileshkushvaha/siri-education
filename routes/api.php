<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BookingPaymentWebhookController;
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
