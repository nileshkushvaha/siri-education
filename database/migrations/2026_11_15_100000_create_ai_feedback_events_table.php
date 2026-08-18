<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit human verdicts on AI output (AI-E0).
 *
 * THIS TABLE EXISTS FOR THE ONE SIGNAL NOTHING ELSE CAPTURES. Every AI
 * feature already records what a human DID — an insight reviewed, a
 * draft used or discarded, a summary approved, a finding confirmed or
 * dismissed — and those outcomes are read directly from the feature
 * tables, never copied here. What none of them records is whether the
 * output was WORTH THE READER'S TIME: an insight can be dutifully
 * reviewed and useless, a draft can be used and then rewritten.
 *
 * NO FREE TEXT. `reason_code` is a fixed enum. A comment box here would
 * become the place a reviewer pastes the sentence that bothered them —
 * student content, in an analytics table, under reporting access rather
 * than the owning domain's rules. Reviewers who need prose have their
 * domain's own review note.
 *
 * NO SUBJECT REFERENCE either: this row points at the AI RUN, not at
 * the instructor, student, message or lesson the run was about. An
 * evaluation record should be able to answer "is prompt v2 better than
 * v1" without being able to answer "what did staff think of this
 * student".
 *
 * `prompt_key`/`prompt_version` are denormalised from the run because
 * comparing prompt versions is this table's whole purpose, and it must
 * keep working after ai_runs telemetry is pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feedback_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('feature_key', 64);
            $table->string('prompt_key', 64)->nullable();
            $table->string('prompt_version', 16)->nullable();

            // Who gave the verdict. Nullable on delete so a departed
            // reviewer's feedback stays countable without keeping their
            // account alive.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 16);
            $table->string('reason_code', 32)->nullable();

            $table->timestamps();

            // One verdict per run per reviewer — changing your mind
            // updates it rather than stacking duplicates into an average.
            $table->unique(['ai_run_id', 'actor_id'], 'ai_feedback_events_unique_verdict');
            $table->index(['feature_key', 'created_at'], 'ai_feedback_events_feature_index');
            $table->index(['prompt_key', 'prompt_version'], 'ai_feedback_events_prompt_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback_events');
    }
};
