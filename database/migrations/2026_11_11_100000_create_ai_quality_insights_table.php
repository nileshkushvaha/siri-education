<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-only, advisory AI briefings about one instructor over one
 * reporting period (P1).
 *
 * WHAT THIS TABLE IS: the validated, structured OUTPUT of a quality
 * insight run, plus who asked for it and who has read it. It is a
 * reading queue for administrators, not an input to anything — no
 * ranking, alert, status change, payout or booking decision reads these
 * rows, and there is deliberately no numeric score column that a future
 * feature could be tempted to sort or threshold on.
 *
 * WHAT IT IS NOT: a copy of what went to the provider. `source_snapshot`
 * holds counts and averages only — the anonymized review excerpts that
 * informed the insight are never stored here. The reviews themselves
 * remain in `lesson_reviews` under their own retention and access
 * rules, and the prompt/response stay nowhere at all (see
 * docs/ai/README.md §Content discipline).
 *
 * `ai_run_id` links to the P0 telemetry row for cost, model, latency
 * and prompt version — nullOnDelete, because telemetry may be pruned on
 * a different schedule than the insight an admin still needs to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_quality_insights', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();

            // The reporting period, stored as the canonical
            // ReportingPeriod triple so an insight can always be
            // reproduced and re-read in the timezone it was run for.
            $table->string('period_preset', 32);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('period_timezone', 64);
            $table->string('period_label', 191);

            $table->string('status', 16);
            $table->string('failure_code', 48)->nullable();

            // The prompt that produced this insight — recorded on the
            // row as well as on ai_runs, so a v1-vs-v2 comparison stays
            // possible even after telemetry is pruned.
            $table->string('prompt_key', 64)->nullable();
            $table->string('prompt_version', 16)->nullable();

            $table->text('summary')->nullable();
            $table->json('strengths')->nullable();
            $table->json('concerns')->nullable();
            $table->text('recommended_review')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('requires_human_review')->default(true);

            // Counts and averages only — never review text.
            $table->json('source_snapshot')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->index(['instructor_id', 'created_at'], 'ai_quality_insights_instructor_index');
            $table->index(['status', 'created_at'], 'ai_quality_insights_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_quality_insights');
    }
};
