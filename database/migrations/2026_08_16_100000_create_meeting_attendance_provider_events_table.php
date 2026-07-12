<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational log of received meeting-attendance provider events
 * (webhook envelopes and sync pulls) — mirrors the payment side's
 * provider-event pattern, with one deliberate difference: raw payloads
 * are NEVER stored (encrypted or otherwise). Only the normalized,
 * sanitized, participant-hashed event list is kept (normalized_events),
 * which is sufficient for bounded retries. The unique
 * (provider, provider_event_id) index is the webhook dedup guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_attendance_provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            $table->string('provider_event_id');
            $table->string('meeting_reference')->nullable();
            $table->foreignUuid('booking_meeting_id')->nullable()->constrained('booking_meetings')->nullOnDelete();
            $table->foreignUuid('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();

            $table->string('source', 16); // webhook | sync
            $table->boolean('signature_valid')->default(true);
            $table->string('processing_status', 32)->default('received');
            $table->string('status_reason')->nullable();

            // Sanitized ProviderAttendanceEvent::toArray() list — hashed
            // participant keys, no tokens/emails/links/raw payloads.
            $table->json('normalized_events')->nullable();
            // Per-event ingestion outcomes (applied/duplicate/late/review/…).
            $table->json('results')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->foreignUuid('duplicate_of_id')->nullable()->constrained('meeting_attendance_provider_events')->nullOnDelete();

            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'meeting_attendance_events_provider_event_unique');
            $table->index(['processing_status', 'received_at'], 'meeting_attendance_events_status_received_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendance_provider_events');
    }
};
