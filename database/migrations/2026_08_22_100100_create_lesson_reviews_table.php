<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17I — one review per eligibility (and, structurally, per
 * booking — a 1-to-1 lesson has exactly one student). Written
 * exclusively by SubmitLessonReviewAction inside the same transaction
 * that marks the source eligibility Used. Nothing here is ever
 * physically deleted; `status` is an open vocabulary so a future
 * moderation phase can add hidden/rejected/archived transitions
 * without a schema change. `content` is always the SANITIZED text —
 * raw submitted text never reaches storage or logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('eligibility_id')->unique()->constrained('lesson_review_eligibilities')->cascadeOnDelete();
            $table->foreignUuid('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignUuid('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('instructor_id')->constrained('users');

            $table->string('review_mode', 32); // public_review | private_feedback

            $table->unsignedTinyInteger('overall_rating');
            $table->unsignedTinyInteger('teaching_quality_rating')->nullable();
            $table->unsignedTinyInteger('communication_rating')->nullable();
            $table->unsignedTinyInteger('punctuality_rating')->nullable();
            $table->unsignedTinyInteger('preparedness_rating')->nullable();
            $table->unsignedTinyInteger('learning_value_rating')->nullable();

            $table->text('content')->nullable(); // sanitized plain text only
            $table->json('tags')->nullable(); // snapshot: [{key, label}, ...]

            $table->string('status', 32)->default('submitted');
            $table->timestamp('submitted_at');

            $table->json('settings_snapshot'); // rating scale/dimensions/length rules in force at submission
            $table->json('sanitization_metadata')->nullable(); // flags only — never raw text

            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['instructor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_reviews');
    }
};
