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

/**
 * Real multi-process race for the refund policy: two genuinely
 * separate processes attempt to resolve the SAME paid booking's refund
 * simultaneously — one via the normal wallet path, one via the
 * exception provider path. Reuses the tests/Concurrency/run-op.php
 * harness used throughout the financial domain. Exactly one must win;
 * the same amount must never be refunded twice.
 */
class BookingRefundConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_wallet_and_provider_refunds_resolve_exactly_once(): void
    {
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $type = BookingType::factory()->create(['requires_approval' => false]);

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
            'payment_reference' => 'PAY-CONCURRENCY-TEST',
        ]);

        BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $student->id,
            'provider' => 'fake',
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => 'captured',
            'idempotency_key' => 'PAY-CONCURRENCY-TEST',
        ]);

        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $results = $this->race([
            ['refund-to-wallet', ['booking_id' => $booking->id]],
            ['refund-via-provider', ['booking_id' => $booking->id, 'actor_id' => $actor->id]],
        ]);

        $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

        // Exactly one refund path must win — the loser sees the booking
        // already refunded (or the payment already resolved) and refuses.
        $this->assertCount(1, $succeeded, json_encode($results));
        $this->assertCount(1, $failed, json_encode($results));

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);
        $this->assertSame(BookingStatus::Cancelled, $booking->status);

        // The payment was resolved by exactly one mechanism — never both.
        $payment = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $this->assertContains($payment->metadata['refund_resolution'], ['wallet_credited', 'provider_refunded']);

        // If the wallet path won, exactly one ledger credit exists (no
        // double-credit even under the race).
        $wallet = Wallet::query()->where('user_id', $student->id)->where('currency_code', 'INR')->first();

        if ($payment->metadata['refund_resolution'] === 'wallet_credited') {
            $this->assertNotNull($wallet);
            $this->assertSame(49900, $wallet->balance_minor);
        } else {
            $this->assertTrue($wallet === null || $wallet->balance_minor === 0);
        }
    }
}
