<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Paid bookings hold their slot until this moment; unpaid
            // reservations past it are released by booking:release-expired.
            $table->timestamp('reserved_until')->nullable()->after('payment_reference')->index();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('reserved_until');
        });
    }
};
