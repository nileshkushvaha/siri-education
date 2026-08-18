<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BookingPaymentWebhookController;
use App\Http\Controllers\Api\MeetingAttendanceWebhookController;
use App\Http\Controllers\Api\PackagePurchaseWebhookController;
use App\Http\Controllers\Api\RazorpayXPayoutWebhookController;
use App\Http\Controllers\Api\RecordingWebhookController;
use App\Http\Controllers\Api\WalletRechargeWebhookController;
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

// Wallet recharge provider notifications — a separate financial domain
// from booking payments; never shares a route, controller, or table
// with the collection side.
Route::post('/webhooks/wallets/recharges/{provider}', WalletRechargeWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.wallets.recharges.webhook');

// Package purchase provider notifications — the generic Payable
// settlement path. A package is not a Booking, so it never shares the
// booking route/controller; it does share the signature service and
// gateway clients, which are the parts worth sharing.
Route::post('/webhooks/packages/purchases/{provider}', PackagePurchaseWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.packages.purchases.webhook');

// Meeting attendance provider notifications — signature verified
// before parsing; gated by meeting.attendance_webhooks_enabled;
// evidence only, never outcome or booking mutations.
Route::post('/webhooks/meetings/attendance/{provider}', MeetingAttendanceWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.meetings.attendance.webhook');

// Provider recording-ready notifications (Zoom today; any provider
// implementing RecordingWebhookProvider). Signature is verified before
// the payload is parsed; the handler only identifies the lesson and
// queues the transfer — a recording is NEVER downloaded in-request.
// Purely a latency optimization: recordings:capture reconciles
// independently, so a missed webhook costs time, never a recording.
Route::post('/webhooks/meetings/recordings/{provider}', RecordingWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.meetings.recordings.webhook');

// RazorpayX instructor payout provider notifications — a separate
// financial domain from booking payments; never shares a route,
// controller, or rate limiter with the collection side.
Route::post('/webhooks/payouts/razorpayx', RazorpayXPayoutWebhookController::class)
    ->middleware('throttle:razorpayx-payout-webhook')
    ->name('api.payouts.razorpayx.webhook');
