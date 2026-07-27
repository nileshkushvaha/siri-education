<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §12.18-21/31: durable recording metadata, linked to
 * the booking and its meeting. student_id/teacher_id are denormalized
 * from the booking (mirroring HomeworkAssignment's own convention) so
 * access-authorization checks never need to join through
 * booking_meetings -> bookings on the hot download path.
 *
 * duration_seconds/size_bytes/mime_type are stored here (not read only
 * from the attached Media row) specifically so they survive past the
 * media file's deletion at retention expiry (requirement #7 — metadata
 * must outlive the file).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_meeting_id');
            $table->uuid('booking_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('teacher_id');
            $table->string('provider', 30);
            $table->string('provider_reference', 191)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 191);
            $table->json('consent_snapshot');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('capture_attempts')->default(0);
            $table->string('failure_code', 50)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('booking_meeting_id')->references('id')->on('booking_meetings')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
            $table->foreign('student_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('teacher_id')->references('id')->on('users')->restrictOnDelete();

            $table->unique('idempotency_key');
            $table->index('status');
            $table->index('expires_at');
            $table->index(['student_id', 'status']);
            $table->index(['teacher_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordings');
    }
};
