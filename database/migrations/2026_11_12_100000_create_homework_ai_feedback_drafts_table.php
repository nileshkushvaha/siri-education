<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-drafted feedback awaiting an instructor (P2).
 *
 * A SEPARATE TABLE FROM THE PUBLISHED FEEDBACK, on purpose.
 * `homework_assignments.feedback` is what the student receives and is
 * written only by ReviewHomeworkAction from what the instructor typed.
 * Nothing here is ever copied into it by code. Keeping the two apart
 * means "what did the tutor actually say?" and "what did a model
 * suggest?" can never be confused later — by a person, a report, or a
 * future feature.
 *
 * NO SCORE, GRADE, MARK OR PASS COLUMN EXISTS, and none may be added.
 * Grading is the instructor's, through the pre-existing
 * `homework_assignments.grade` field, which no AI code path writes.
 *
 * The student's submission is NOT stored here. `source_snapshot` holds
 * shape and size only (subject, level, character count, whether an
 * attachment existed). The submission stays in its own table under its
 * own rules; this row records what was drafted from it, not what was
 * read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_ai_feedback_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('homework_assignment_id')->constrained('homework_assignments')->cascadeOnDelete();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();

            // The instructor who asked. Generation is always explicitly
            // instructor-initiated — there is no system-generated draft.
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->string('status', 16);
            $table->string('failure_code', 48)->nullable();

            $table->string('prompt_key', 64)->nullable();
            $table->string('prompt_version', 16)->nullable();

            $table->text('summary')->nullable();
            $table->json('strengths')->nullable();
            $table->json('improvements')->nullable();
            $table->text('suggested_feedback')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('requires_instructor_review')->default(true);

            $table->json('source_snapshot')->nullable();

            // "Used" means the draft was pulled into the instructor's
            // editor — provenance, never publication.
            $table->timestamp('used_at')->nullable();
            $table->timestamp('discarded_at')->nullable();

            $table->timestamps();

            $table->index(['homework_assignment_id', 'created_at'], 'homework_ai_drafts_assignment_index');
            $table->index(['status', 'created_at'], 'homework_ai_drafts_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_ai_feedback_drafts');
    }
};
