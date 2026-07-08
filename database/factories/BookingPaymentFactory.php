<?php

namespace Database\Factories;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookingPayment>
 */
class BookingPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'user_id' => null,
            'provider' => 'razorpay',
            'provider_order_id' => 'order_'.Str::upper(Str::random(14)),
            'provider_payment_id' => null,
            'amount_minor' => 499900,
            'currency_code' => 'INR',
            'status' => BookingPaymentRecordStatus::Pending,
            'payment_method' => null,
            'idempotency_key' => 'PAY-'.strtoupper(Str::random(12)),
            'metadata' => [],
            'paid_at' => null,
            'failed_at' => null,
        ];
    }

    public function captured(): static
    {
        return $this->state(fn (): array => [
            'provider_payment_id' => 'pay_'.Str::upper(Str::random(14)),
            'status' => BookingPaymentRecordStatus::Captured,
            'payment_method' => 'upi',
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => BookingPaymentRecordStatus::Failed,
            'failed_at' => now(),
        ]);
    }
}
