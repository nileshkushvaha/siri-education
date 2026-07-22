<?php

namespace Database\Factories;

use App\Models\BookingPayment;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_number' => 'STEM/INV/'.now()->year.'/'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'source_type' => BookingPayment::class,
            'source_id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'student_name' => fake()->name(),
            'billing_country' => fake()->country(),
            'amount_minor' => 499900,
            'currency_code' => 'INR',
            'payment_date' => now(),
            'payment_reference' => 'PAY-'.strtoupper(Str::random(12)),
            'service_description' => 'Payment for booking',
            'booking_reference' => 'BK-'.strtoupper(Str::random(10)),
            'wallet_recharge_reference' => null,
            'organization_name' => 'SIRI Education',
            'organization_address' => '123 Learning Lane',
            'organization_support_email' => 'support@example.test',
            'organization_support_phone' => '+1 555 0100',
            'organization_website_url' => 'https://example.test',
            'issued_at' => now(),
        ];
    }
}
