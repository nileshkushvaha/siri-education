<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-assisted lesson summaries awaiting, or carrying, an instructor's
 * approval (P3).
 *
 * TWO TEXTS, KEPT APART. `lesson_summary` is what the model drafted;
 * `approved_summary` is what the instructor actually approved after
 * editing. Storing them in one column would erase the distinction
 * between "a model suggested this" and "a tutor stands behind this" —
 * and that distinction is the entire point of the feature. Only
 * `approved_summary` is documentation of the lesson.
 *
 * NOTHING HERE IS AUTHORITATIVE ABOUT THE STUDENT. There is no mastery,
 * level, progress, score or grade column, and none may be added: such a
 * column would become a metric something later charts, which is how a
 * language model's impression of one lesson quietly turns into a
 * progress signal. Progress remains owned by the learning-plan domain
 * and its deterministic recalculation.
 *
 * `lessons.completion_notes` is untouched by this table — the
 * instructor's own note stays their own.
 *
 * ONE ROW PER LESSON, enforced by a unique index: a lesson has one
 * summary of record, and regenerating replaces the draft in place
 * rather than accumulating competing versions of what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_ai_summaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('lesson_id')->unique()->constrained('lessons')->cascadeOnDelete();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();

            // Always the instructor who asked — generation is never
            // automatic and never system-initiated.
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->string('status', 16);
            $table->string('failure_code', 48)->nullable();

            $table->string('prompt_key', 64)->nullable();
            $table->string('prompt_version', 16)->nullable();

            // ── The model's draft ─────────────────────────────────────
            $table->text('lesson_summary')->nullable();
            $table->json('topics_covered')->nullable();
            $table->json('strengths_observed')->nullable();
            $table->json('practice_recommendations')->nullable();
            $table->json('next_focus')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('requires_instructor_review')->default(true);

            // ── The instructor's approved text ────────────────────────
            $table->text('approved_summary')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('discarded_at')->nullable();

            // Which kinds of context were available, never their content.
            $table->json('source_snapshot')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'lesson_ai_summaries_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_ai_summaries');
    }
};
