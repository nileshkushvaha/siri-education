<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication-safety findings about individual messages (P4).
 *
 * `source_type` IS THE POINT OF THIS TABLE. Deterministic findings and
 * AI findings live together so an administrator has one place to look,
 * and are never presented as the same kind of claim: a deterministic
 * finding is a verifiable fact about the text, an AI finding is an
 * opinion with a confidence that may simply be wrong. Any consumer that
 * cannot tell them apart is reading this table incorrectly.
 *
 * NO MESSAGE TEXT IS COPIED HERE. `message_id` points at the message,
 * which lives in `messages` under its own retention and access rules;
 * duplicating the body would create a second, longer-lived copy of a
 * private conversation between a student — often a minor — and their
 * tutor, in a table built for admin review. `reason` is the model's
 * one-sentence description of the message, capped short by schema, and
 * is the only narrative stored.
 *
 * NO ENFORCEMENT COLUMN EXISTS, and none may be added: no
 * `blocked`, `banned`, `restricted`, `action_taken`. A finding is
 * evidence for a person. Consequences happen through the existing
 * account and messaging-restriction paths, recorded there.
 *
 * Deliberately NOT `suspicious_activity_flags`. That table is the
 * account-level compliance queue fed by deterministic threshold rules,
 * and its `evidence` contract explicitly forbids narrative text. A
 * per-message probabilistic finding does not belong in it — instead,
 * RepeatedCommunicationRiskFindingsRule counts CONFIRMED findings and
 * raises a normal compliance flag through the existing service, so
 * account-level review keeps exactly one screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_safety_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            // Denormalised for the escalation rule and the admin queue:
            // both ask "which user's messages", and neither should have
            // to join through conversations to find out.
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();

            $table->string('source_type', 24);
            $table->string('category', 32);
            $table->string('risk_level', 8);

            // AI findings only; a deterministic finding's reason is its
            // rule keys, which are already in `detected_patterns`.
            $table->string('reason', 300)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();

            /** Which deterministic rules or triage phrases fired — never message text. */
            $table->json('detected_patterns')->nullable();

            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('prompt_key', 64)->nullable();
            $table->string('prompt_version', 16)->nullable();

            $table->string('status', 16)->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            // One finding per message per source: re-analysis updates in
            // place rather than stacking duplicates in the review queue.
            $table->unique(['message_id', 'source_type'], 'message_safety_findings_unique_source');
            $table->index(['status', 'created_at'], 'message_safety_findings_status_index');
            $table->index(['sender_id', 'status'], 'message_safety_findings_sender_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_safety_findings');
    }
};
