<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Participant-submitted attendance claims (Phase 17D). Append-only
 * history: a conflicting resubmission creates a new row, an identical
 * one dedups on the unique (lesson_id, participant, fingerprint).
 * Claims are evidence candidates — only an accepted review feeds them
 * through LessonAttendanceService; nothing here mutates outcomes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_attendance_confirmations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('participant', 16); // student | instructor — derived, never client-supplied
            $table->foreignId('submitted_by')->constrained('users');

            $table->timestamp('claimed_joined_at')->nullable();
            $table->timestamp('claimed_left_at')->nullable();
            $table->unsignedSmallInteger('claimed_attended_minutes')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('fingerprint', 64);

            $table->string('status', 32)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();

            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['lesson_id', 'participant', 'fingerprint'], 'lesson_attendance_confirmations_dedup_unique');
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_attendance_confirmations');
    }
};
