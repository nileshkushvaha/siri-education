<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->unique();

            // Creation mechanism: 'manual' | 'google_meet' | 'zoom_future'.
            // A manual entry's descriptive sub-label (zoom_manual,
            // google_meet_manual, other) lives in metadata, not here —
            // this column identifies which MeetingProviderInterface
            // implementation produced the row.
            $table->string('provider', 50);
            $table->string('provider_meeting_id')->nullable();
            $table->string('provider_event_id')->nullable();

            $table->string('join_url', 2048)->nullable();
            // Host/start link and join password — never exposed to
            // students; hidden at the model level too.
            $table->string('host_url', 2048)->nullable();
            $table->string('password', 255)->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64)->default('UTC');

            $table->string('status', 20)->default('pending');
            $table->string('failure_reason', 500)->nullable();
            // Sanitized provider metadata only — never raw payloads/secrets.
            $table->json('metadata')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Meeting history is booking history — never cascaded away (Phase
            // 17U.3: folded directly into the baseline; previously fixed up
            // by a later migration removed in this phase).
            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_meetings');
    }
};
