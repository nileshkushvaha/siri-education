<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `booking_types.price`/`currency` are removed —
 * `StudentLessonPrice` is now the only source of a paid
 * lesson's student-facing price; `BookingType` defines booking
 * behavior only. Does NOT touch `bookings.price`/`currency` (the
 * per-booking snapshot taken at booking-creation time) or any
 * `booking_payments`/wallet column — those are unrelated columns on
 * unrelated tables and must survive this migration untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->dropColumn(['price', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->decimal('price', 10, 2)->nullable();
            $table->char('currency', 3)->nullable();
        });
    }
};
