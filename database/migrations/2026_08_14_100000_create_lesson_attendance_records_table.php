<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One authoritative attendance record per lesson (unique lesson_id) —
 * the merged, idempotent aggregate of every attendance evidence event
 * (provider webhooks/sync, instructor confirmation, admin override,
 * system fallback). Raw provider payloads are never stored — only a
 * sanitized metadata snapshot. All timestamps are UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_attendance_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->unique()->constrained('lessons')->cascadeOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignUuid('booking_meeting_id')->nullable()->constrained('booking_meetings')->nullOnDelete();

            $table->timestamp('student_first_joined_at')->nullable();
            $table->timestamp('student_last_left_at')->nullable();
            $table->unsignedInteger('student_attended_seconds')->default(0);
            $table->unsignedSmallInteger('student_join_count')->default(0);

            $table->timestamp('instructor_first_joined_at')->nullable();
            $table->timestamp('instructor_last_left_at')->nullable();
            $table->unsignedInteger('instructor_attended_seconds')->default(0);
            $table->unsignedSmallInteger('instructor_join_count')->default(0);

            // Most recent evidence source applied to this record.
            $table->string('source', 32)->nullable();
            $table->string('provider_reference')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('technical_issue_reported_at')->nullable();

            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('booking_id');
            $table->index('finalized_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_attendance_records');
    }
};
