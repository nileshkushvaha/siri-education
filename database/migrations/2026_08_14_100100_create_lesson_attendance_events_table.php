<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only attendance evidence log. Each ingested evidence event
 * (one webhook notification, one sync row, one manual confirmation)
 * is stored once — the unique (lesson_id, fingerprint) index is the
 * idempotency guarantee: replayed webhooks and repeated commands hit
 * the same fingerprint and are ignored. Aggregates on the parent
 * lesson_attendance_records row are recomputed from these rows, so
 * out-of-order arrival always merges to the same result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_attendance_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_attendance_record_id')->constrained('lesson_attendance_records')->cascadeOnDelete();
            $table->foreignUuid('lesson_id')->constrained('lessons')->cascadeOnDelete();

            $table->string('participant', 16); // student | instructor
            $table->string('source', 32);
            $table->string('provider_reference')->nullable();
            $table->string('provider_event_id')->nullable();
            // sha256 of the provider event id (or the normalized evidence
            // tuple when the source has no event id) — dedup key.
            $table->string('fingerprint', 64);

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('attended_seconds')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['lesson_id', 'fingerprint']);
            $table->index(['lesson_attendance_record_id', 'participant'], 'lesson_attendance_events_record_participant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_attendance_events');
    }
};
