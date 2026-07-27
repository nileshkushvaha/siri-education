<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data-provenance decision (Outcome A, §6.2): `booking_activities.action`
     * is already a structured, enum-backed, typed column (never free text)
     * that records every 'rescheduled' lifecycle event — an authoritative
     * reschedule-count source with no new schema concept. The only gap is
     * an index supporting "count rescheduled activity rows within a date
     * range" (the existing index is `(booking_id, created_at)`, which
     * doesn't serve a cross-booking `action = 'rescheduled'` query the new
     * operations report actually issues).
     */
    public function up(): void
    {
        Schema::table('booking_activities', function (Blueprint $table): void {
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('booking_activities', function (Blueprint $table): void {
            $table->dropIndex(['action', 'created_at']);
        });
    }
};
