<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `booking_types.max_attendees` (and the shared-slot
 * group-capacity mechanism it drove) is removed — the SRS Version 1
 * booking model is one booking = one student + one instructor + one
 * exclusive slot. No approved booking type ever set this above 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->dropColumn('max_attendees');
        });
    }

    public function down(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_attendees')->nullable();
        });
    }
};
