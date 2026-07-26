<?php

declare(strict_types=1);

namespace Tests\Feature\Booking\Concurrency;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Carbon\CarbonImmutable;

/**
 * Real multi-process race proving the frozen ineligible-cancellation
 * disposition can only ever be recorded once, even under a
 * genuine duplicate delivery of the SAME cancellation event (e.g. two
 * queue workers picking up the same job, or a webhook/listener retry
 * that overlaps the original execution). Mirrors the harness pattern
 * proven in BookingRefundConcurrencyTest.
 */
class CancellationRefundDispositionConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_duplicate_ineligible_disposition_resolves_exactly_once(): void
    {
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $type = BookingType::factory()->create(['requires_approval' => false]);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'price' => '499.00',
            'currency' => 'INR',
            'payment_reference' => 'PAY-DISPOSITION-RACE',
        ]);

        BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $student->id,
            'provider' => 'fake',
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => 'captured',
            'idempotency_key' => 'PAY-DISPOSITION-RACE',
        ]);

        $args = [
            'booking_id' => $booking->id,
            'cutoff_at' => $startsAt->subHours(24)->toIso8601String(),
            'window_hours' => 24,
            'cancelled_at' => $startsAt->subHour()->toIso8601String(),
            'starts_at' => $startsAt->toIso8601String(),
        ];

        $results = $this->race([
            ['record-ineligible-cancellation', $args],
            ['record-ineligible-cancellation', $args],
        ]);

        $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

        // Exactly one delivery wins; the duplicate is rejected outright,
        // never silently reapplied.
        $this->assertCount(1, $succeeded, json_encode($results));
        $this->assertCount(1, $failed, json_encode($results));
        $this->assertSame('App\Booking\Exceptions\BookingException', $failed[0]['exception']);

        // The booking was never refunded — payment_status stays Paid,
        // and no wallet entry was ever created for it.
        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame('not_eligible_late_cancellation', $payment->metadata['refund_resolution']);

        $wallet = Wallet::query()->where('user_id', $student->id)->where('currency_code', 'INR')->first();
        $this->assertTrue($wallet === null || $wallet->balance_minor === 0);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }
}
