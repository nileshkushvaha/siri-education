<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17R — append-only edit history for student reviews. One row
 * per successful edit, freezing the review's PREVIOUS state (already
 * sanitized — raw prohibited text never reached the review row, so it
 * can never reach a revision either). `review_version` is the version
 * the review carried before that edit; unique(review, version) makes
 * a duplicate/replayed append structurally impossible. Rows are never
 * updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_review_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_review_id')->constrained('lesson_reviews')->cascadeOnDelete();

            $table->unsignedInteger('review_version'); // the review's version BEFORE the edit this row records

            $table->unsignedTinyInteger('previous_overall_rating');
            $table->unsignedTinyInteger('previous_teaching_quality_rating')->nullable();
            $table->unsignedTinyInteger('previous_communication_rating')->nullable();
            $table->unsignedTinyInteger('previous_punctuality_rating')->nullable();
            $table->unsignedTinyInteger('previous_preparedness_rating')->nullable();
            $table->unsignedTinyInteger('previous_learning_value_rating')->nullable();

            $table->text('previous_content')->nullable(); // sanitized text only — raw never stored
            $table->json('previous_tags')->nullable();
            $table->string('previous_status', 32);
            $table->json('previous_sanitization_metadata')->nullable(); // flags only
            $table->json('previous_moderation_snapshot')->nullable();

            $table->foreignId('edited_by')->constrained('users');
            $table->timestamp('edited_at');

            $table->timestamps();

            $table->unique(['lesson_review_id', 'review_version'], 'lesson_review_revisions_review_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_review_revisions');
    }
};
