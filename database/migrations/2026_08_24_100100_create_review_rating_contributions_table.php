<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exactly one row per LessonReview (unique review_id),
 * tracking whether that review currently contributes to its
 * instructor's rating aggregate. Reconciliation is idempotent by
 * construction: a publish/hide/restore/reject/archive event just
 * compares the review's CURRENT status against `included` and applies
 * the delta only when they disagree — repeating the same event, or
 * events arriving out of order, always converges to the same result.
 * Snapshots preserve exactly what was added, so removal always
 * subtracts the same values that were summed in (never the review's
 * possibly-different current values, even though review content is
 * itself immutable post-submission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_rating_contributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->unique()->constrained('lesson_reviews')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('users');

            $table->boolean('included')->default(false);

            $table->unsignedTinyInteger('overall_rating')->nullable();
            $table->unsignedTinyInteger('teaching_quality_rating')->nullable();
            $table->unsignedTinyInteger('communication_rating')->nullable();
            $table->unsignedTinyInteger('punctuality_rating')->nullable();
            $table->unsignedTinyInteger('preparedness_rating')->nullable();
            $table->unsignedTinyInteger('learning_value_rating')->nullable();
            $table->string('lesson_type', 16)->nullable(); // paid | demo, snapshot from the eligibility

            $table->unsignedInteger('applied_review_version')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            $table->index(['instructor_id', 'included']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_rating_contributions');
    }
};
