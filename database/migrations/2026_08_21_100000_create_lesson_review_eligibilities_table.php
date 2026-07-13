<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17H — durable student review eligibility. Exactly one record
 * per (lesson, student): outcome corrections transition the SAME row
 * (open → revoked, open → expired, used → manual_review, revoked →
 * open again) rather than creating new rows — history is preserved in
 * `history`, never deleted. `metadata` snapshots the review-settings
 * values in force at creation time, so a later settings change never
 * silently changes what an already-open window means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_review_eligibilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('instructor_id')->constrained('users');

            $table->string('lesson_type', 16); // paid | demo
            $table->string('eligibility_mode', 32); // public_review | private_feedback
            $table->string('status', 32)->default('open');

            $table->timestamp('opens_at');
            $table->timestamp('expires_at');
            $table->string('outcome_snapshot', 32); // finalized LessonOutcome value at creation/restore
            $table->unsignedInteger('source_outcome_version');

            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 500)->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->json('history')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['lesson_id', 'student_id'], 'lesson_review_eligibilities_lesson_student_unique');
            $table->index('student_id', 'lesson_review_eligibilities_student_index');
            $table->index(['status', 'expires_at'], 'lesson_review_eligibilities_status_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_review_eligibilities');
    }
};
