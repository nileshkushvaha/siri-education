<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable replay protection for recording webhooks, mirroring the
 * meeting-attendance and payment provider-event tables.
 *
 * The unique (provider, provider_event_id) index IS the deduplication
 * guarantee — not an in-memory check, not a cache entry — so a webhook
 * Zoom redelivers hours later still resolves to "already seen".
 *
 * Raw payloads are NEVER stored. A recording webhook carries a
 * short-lived download token and a signed download URL; persisting the
 * envelope would turn an operational log into a credential store. Only
 * the identifiers needed to find the lesson again are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            // Derived deterministically from the provider's own event
            // identity (event name + meeting uuid + event timestamp), so
            // the same delivery always produces the same key.
            $table->string('provider_event_id', 191);
            $table->string('event_type', 64);
            // The provider's meeting identifier — enough to find the
            // BookingMeeting, and nothing more.
            $table->string('meeting_reference', 191)->nullable();
            $table->foreignUuid('booking_meeting_id')->nullable()->constrained('booking_meetings')->nullOnDelete();
            $table->foreignUuid('recording_id')->nullable()->constrained('recordings')->nullOnDelete();

            $table->string('processing_status', 32)->default('received');
            $table->string('status_reason')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'recording_provider_events_unique');
            $table->index(['processing_status', 'received_at'], 'recording_provider_events_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_provider_events');
    }
};
