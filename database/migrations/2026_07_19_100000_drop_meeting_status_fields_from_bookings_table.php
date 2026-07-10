<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Superseded by the dedicated `booking_meetings` table
 * (2026_07_19_100001_create_booking_meetings_table): meeting_status,
 * meeting_host_url, meeting_password, and meeting_metadata (added
 * 2026_07_18_100000, this phase's earlier column-based approach) would
 * otherwise duplicate meeting data across two structures. The
 * pre-existing meeting_provider/meeting_ref/meeting_url columns are
 * kept — BookingMeetingService still mirrors into them for backward
 * compatibility with BookingConfirmedNotification's email CTA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['meeting_status']);
            $table->dropColumn(['meeting_status', 'meeting_host_url', 'meeting_password', 'meeting_metadata']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('meeting_status', 20)->default('pending')->after('meeting_url');
            $table->string('meeting_host_url', 2048)->nullable()->after('meeting_status');
            $table->string('meeting_password', 255)->nullable()->after('meeting_host_url');
            $table->json('meeting_metadata')->nullable()->after('meeting_password');

            $table->index('meeting_status');
        });
    }
};
